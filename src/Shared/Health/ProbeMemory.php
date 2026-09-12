<?php

declare(strict_types=1);

namespace App\Shared\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Redis memory of verified admin keys, ordered by a denial token that never
 * repeats (design decision 4). Per key hash h (sha256(h) in the key names):
 *   probe-auth:gen:<h>  a random 128-bit token every denied answer REPLACES
 *   probe-auth:v:<h>    {"gen","exp","verified_at"} of the last verification
 * remember() is a compare-and-set on the token read before the lookup, so a
 * verification whose statement predates a revocation cannot land after the
 * denial that observed it; a token that expired between the read and the
 * write also refuses (absent ≠ read) — a harmless false negative. consult()
 * bounds the verification's age from verified_at, not from the write.
 *
 * Every operation is one Redis command under an absolute deadline of its own
 * (BoundedRedisConnection: a reply arriving in pieces cannot outlast it); the
 * whole request sends exactly three — AUTH/PING, GET, then EVAL or GET — on
 * one connection. The URL's database index is not selected — the memory lives in
 * database 0 (REDIS_URL carries none in this project; a SELECT would be a
 * fourth command outside the budget).
 */
final class ProbeMemory implements ProbeMemoryInterface
{
    public const int TTL_SECONDS = 300;
    public const float OPERATION_TIMEOUT = ProbeAuthorizer::REDIS_ALLOWANCE / 4;

    private const string REMEMBER = <<<'LUA'
        local current = redis.call('GET', KEYS[1])
        if current == false then current = '' end
        if current ~= ARGV[1] then return 0 end
        redis.call('SET', KEYS[2], ARGV[2], 'PX', ARGV[3])
        return 1
        LUA;

    private const string DENY = <<<'LUA'
        redis.call('SET', KEYS[1], ARGV[1], 'PX', ARGV[2])
        redis.call('DEL', KEYS[2])
        return 1
        LUA;

    private ?BoundedRedisConnection $connection = null;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
        private readonly float $operationTimeout = self::OPERATION_TIMEOUT,
    ) {
    }

    public function connect(): void
    {
        $this->close();
        $connection = $this->guard('connect', fn (): BoundedRedisConnection => BoundedRedisConnection::connect($this->redisUrl, $this->operationTimeout));

        $password = BoundedRedisConnection::password($this->redisUrl);
        // with no password a PING takes AUTH's slot, so every request costs the same three commands
        $answer = $this->guard('auth', static fn (): string|int|null => null === $password
            ? $connection->command('auth', 'PING')
            : $connection->command('auth', 'AUTH', $password));
        if ('OK' !== $answer && 'PONG' !== $answer) {
            $connection->close();
            throw new MemoryUnavailable('auth', new \RuntimeException('the server did not accept the connection'));
        }

        $this->connection = $connection;
    }

    public function token(#[\SensitiveParameter] string $hash): string
    {
        $connection = $this->connection('token');
        $token = $this->guard('token', static fn (): string|int|null => $connection->command('token', 'GET', self::tokenKey($hash)));

        return \is_string($token) ? $token : '';
    }

    public function remember(#[\SensitiveParameter] string $hash, string $token, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $verifiedAt): bool
    {
        $value = json_encode([
            'gen' => $token,
            'exp' => $expiresAt?->format(\DATE_ATOM),
            'verified_at' => $verifiedAt->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
        $connection = $this->connection('remember');
        $stored = $this->guard('remember', static fn (): string|int|null => $connection->command(
            'remember', 'EVAL', self::REMEMBER, '2', self::tokenKey($hash), self::valueKey($hash), $token, $value, (string) (self::TTL_SECONDS * 1000),
        ));

        return 1 === $stored;
    }

    public function deny(#[\SensitiveParameter] string $hash): void
    {
        $connection = $this->connection('deny');
        $this->guard('deny', static fn (): string|int|null => $connection->command(
            'deny', 'EVAL', self::DENY, '2', self::tokenKey($hash), self::valueKey($hash), bin2hex(random_bytes(16)), (string) (self::TTL_SECONDS * 1000),
        ));
    }

    public function consult(#[\SensitiveParameter] string $hash, \DateTimeImmutable $now): bool
    {
        $connection = $this->connection('consult');
        $raw = $this->guard('consult', static fn (): string|int|null => $connection->command('consult', 'GET', self::valueKey($hash)));
        if (!\is_string($raw)) {
            return false;
        }
        $value = json_decode($raw, true);
        if (!\is_array($value) || !\is_string($value['verified_at'] ?? null)) {
            return false;
        }
        $verifiedAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value['verified_at']);
        if (false === $verifiedAt || $now->getTimestamp() - $verifiedAt->getTimestamp() > self::TTL_SECONDS) {
            return false;
        }
        if (null !== ($value['exp'] ?? null)) {
            $expiresAt = \is_string($value['exp']) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value['exp']) : false;
            if (false === $expiresAt || $expiresAt <= $now) {
                return false;
            }
        }

        return true;
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }

    private function connection(string $operation): BoundedRedisConnection
    {
        return $this->connection ?? throw new MemoryUnavailable($operation, new \LogicException('not connected'));
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function guard(string $name, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RedisOperationFailed $e) {
            throw new MemoryUnavailable($name, $e->getPrevious() ?? $e);
        }
    }

    private static function tokenKey(#[\SensitiveParameter] string $hash): string
    {
        return 'probe-auth:gen:'.hash('sha256', $hash);
    }

    private static function valueKey(#[\SensitiveParameter] string $hash): string
    {
        return 'probe-auth:v:'.hash('sha256', $hash);
    }
}
