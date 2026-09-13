<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * A Redis connection whose every operation is bounded by a wall-clock
 * deadline, not by a per-read timeout (Gate 2 round 1, finding 2): ext-redis
 * and PHP's stream layer restart their timeout on each successful read, so a
 * server that delivers one byte every 200 ms makes progress forever and the
 * probe's allowance means nothing. Here the deadline is taken once, when the
 * operation starts, and every wait is measured against it.
 *
 * Speaks the little of RESP the probe needs — AUTH, PING, GET, EVAL, and the
 * status, integer and bulk replies they produce. A raw client is warranted
 * where a PostgreSQL one was not: the protocol is length-prefixed and has no
 * authentication negotiation, and the alternative is an unbounded client on a
 * path whose whole purpose is to be bounded.
 */
final class BoundedRedisConnection
{
    /** No reply of this probe is anywhere near this; a larger one is a broken or hostile server. */
    private const int MAX_REPLY_BYTES = 65536;

    private string $buffer = '';

    /**
     * @param resource $stream
     */
    private function __construct(
        private $stream,
        private readonly float $operationTimeout,
    ) {
    }

    /**
     * Connects (and nothing else — AUTH or PING is the caller's first command,
     * so every operation is one countable step of the allowance).
     *
     * @throws RedisOperationFailed named `connect`
     */
    public static function connect(string $url, float $operationTimeout): self
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'])) {
            throw new RedisOperationFailed('connect', new \InvalidArgumentException('REDIS_URL is not a parseable URL.'));
        }

        $stream = @stream_socket_client(
            \sprintf('tcp://%s:%d', $parts['host'], $parts['port'] ?? 6379),
            $errno,
            $errstr,
            $operationTimeout,
            \STREAM_CLIENT_CONNECT,
        );
        if (false === $stream) {
            throw new RedisOperationFailed('connect', new \RuntimeException(\sprintf('connect failed (%d)', $errno)));
        }
        stream_set_blocking($stream, false);

        return new self($stream, $operationTimeout);
    }

    public static function password(string $url): ?string
    {
        $parts = parse_url($url);

        return \is_array($parts) && isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
    }

    /**
     * One command and its reply, start to finish within the operation timeout —
     * a reply arriving in pieces cannot extend it.
     *
     * @throws RedisOperationFailed named $operation
     */
    public function command(string $operation, #[\SensitiveParameter] string ...$words): string|int|null
    {
        $deadline = microtime(true) + $this->operationTimeout;
        $request = \sprintf('*%d'."\r\n", \count($words));
        foreach ($words as $word) {
            $request .= \sprintf('$%d'."\r\n%s\r\n", \strlen($word), $word);
        }

        $this->write($operation, $request, $deadline);

        return $this->readReply($operation, $deadline);
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->buffer = '';
    }

    private function write(string $operation, string $request, float $deadline): void
    {
        while ('' !== $request) {
            $this->awaitReadiness($operation, $deadline, forWriting: true);
            $written = @fwrite($this->stream, $request);
            if (false === $written) {
                throw new RedisOperationFailed($operation, new \RuntimeException('the connection was lost while sending'));
            }
            $request = substr($request, $written);
        }
    }

    private function readReply(string $operation, float $deadline): string|int|null
    {
        $line = $this->readLine($operation, $deadline);
        $payload = substr($line, 1);

        return match ($line[0] ?? '') {
            '+' => $payload,
            ':' => (int) $payload,
            '$' => $this->readBulk($operation, (int) $payload, $deadline),
            '-' => throw new RedisOperationFailed($operation, new \RuntimeException('the server refused the command')),
            default => throw new RedisOperationFailed($operation, new \RuntimeException('unexpected reply type')),
        };
    }

    private function readBulk(string $operation, int $length, float $deadline): ?string
    {
        if (-1 === $length) {
            return null;
        }
        if ($length < 0 || $length > self::MAX_REPLY_BYTES) {
            throw new RedisOperationFailed($operation, new \RuntimeException('reply length out of range'));
        }
        while (\strlen($this->buffer) < $length + 2) {
            $this->fill($operation, $deadline);
        }
        $value = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length + 2);

        return $value;
    }

    private function readLine(string $operation, float $deadline): string
    {
        while (false === ($end = strpos($this->buffer, "\r\n"))) {
            if (\strlen($this->buffer) > self::MAX_REPLY_BYTES) {
                throw new RedisOperationFailed($operation, new \RuntimeException('reply line out of range'));
            }
            $this->fill($operation, $deadline);
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);

        return $line;
    }

    private function fill(string $operation, float $deadline): void
    {
        $this->awaitReadiness($operation, $deadline, forWriting: false);
        $chunk = @fread($this->stream, 8192);
        if (false === $chunk || ('' === $chunk && feof($this->stream))) {
            throw new RedisOperationFailed($operation, new \RuntimeException('the connection was closed mid-reply'));
        }
        $this->buffer .= $chunk;
    }

    /**
     * Waits for the socket, never past the deadline — the remaining time is
     * recomputed from it on every wait, so progress does not buy more of it.
     */
    private function awaitReadiness(string $operation, float $deadline, bool $forWriting): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RedisOperationFailed($operation, new \RuntimeException('the operation did not finish within its share of the allowance'));
        }
        $streams = [$this->stream];
        $read = $forWriting ? null : $streams;
        $write = $forWriting ? $streams : null;
        $except = null;
        $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - floor($remaining)) * 1_000_000));
        if (false === $ready) {
            throw new RedisOperationFailed($operation, new \RuntimeException('waiting on the connection failed'));
        }
        if (0 === $ready) {
            throw new RedisOperationFailed($operation, new \RuntimeException('the operation did not finish within its share of the allowance'));
        }
    }
}
