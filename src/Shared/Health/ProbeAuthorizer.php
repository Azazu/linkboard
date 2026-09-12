<?php

declare(strict_types=1);

namespace App\Shared\Health;

use App\Auth\ApiKey\ApiKeyGenerator;
use App\Auth\ApiKey\ApiKeyHeaderExtractor;
use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Authorizes the prod deep probe for a valid admin API key, outside the
 * firewall and on bounded clients of its own (design decisions 1–2, 5):
 *   header regex (no I/O on a non-key) → hash
 *   Redis: connect, AUTH/PING, the denial token — 0.25 s each
 *   database: bin/probe-key-lookup in a child killed at 2.5 s
 *   Redis: one post-lookup command — remember / deny / consult — 0.25 s
 * Total I/O ≤ 3.5 s in every failure mode. A verified answer is honoured even
 * when Redis failed before the lookup (it is just not remembered); a denied
 * answer is final; an unavailable database consults the memory. Every refusal
 * is the same `false`; the reason lives in a warning that never names the key.
 */
#[WithMonologChannel('health')]
final class ProbeAuthorizer
{
    public const float DATABASE_ALLOWANCE = 2.5;
    public const float REDIS_ALLOWANCE = 1.0;

    public function __construct(
        private readonly KeyLookupInterface $lookup,
        private readonly ProbeMemoryInterface $memory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function authorize(#[\SensitiveParameter] ?string $authorizationHeader): bool
    {
        $plaintext = ApiKeyHeaderExtractor::keyFromHeader($authorizationHeader);
        if (null === $plaintext) {
            return false;
        }
        $hash = ApiKeyGenerator::hash($plaintext);

        try {
            $token = null;
            try {
                $this->memory->connect();
                $token = $this->memory->token($hash);
            } catch (MemoryUnavailable $e) {
                $this->memoryWarning($e);
            }

            $outcome = $this->lookup->lookup($hash, self::DATABASE_ALLOWANCE);

            if ($outcome->isVerified()) {
                if (null !== $token) {
                    $this->guarded(fn () => $this->memory->remember($hash, $token, $outcome->expiresAt, $this->clock->now()));
                }

                return true;
            }

            if ($outcome->isDenied()) {
                if (null !== $token) {
                    $this->guarded(fn () => $this->memory->deny($hash));
                }

                return false;
            }

            $this->logger->warning('deep probe authorization: database unavailable', ['reason' => $outcome->reason]);
            if (null === $token) {
                return false;
            }

            return true === $this->guarded(fn () => $this->memory->consult($hash, $this->clock->now()));
        } finally {
            $this->memory->close();
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T|null null when the memory failed
     */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (MemoryUnavailable $e) {
            $this->memoryWarning($e);

            return null;
        }
    }

    private function memoryWarning(MemoryUnavailable $e): void
    {
        $this->logger->warning('deep probe authorization: memory unavailable', [
            'operation' => $e->operation,
            'exception' => ($e->getPrevious() ?? $e)::class,
        ]);
    }
}
