<?php

declare(strict_types=1);

namespace App\Shared\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks the two runtime dependencies with a hard per-check timeout so a
 * hung dependency cannot hang the probe (specification: health-check).
 *
 * Deliberately uses raw PDO / ext-redis clients built from the DSNs rather
 * than the application's Doctrine connection or cache pool: the probe must
 * observe a dependency that is down without depending on services that
 * fail to construct in that very situation.
 */
final readonly class HealthProbe
{
    public const int TIMEOUT_SECONDS = 2;

    public function __construct(
        #[Autowire(env: 'DATABASE_URL')]
        private string $databaseUrl,
        #[Autowire(env: 'REDIS_URL')]
        private string $redisUrl,
    ) {
    }

    public function run(): HealthReport
    {
        return new HealthReport([
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ]);
    }

    private function checkDatabase(): bool
    {
        try {
            $statement = $this->databaseConnection()->query('SELECT 1');

            return false !== $statement && 1 === (int) $statement->fetchColumn();
        } catch (\PDOException|\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The connection the database check runs on: connect timeout AND
     * server-side statement timeout are both bounded, so a server that
     * accepts the TCP connection and then stops answering fails the probe
     * within the budget instead of hanging it. Public so the hang case can
     * be tested with a slow query on exactly this connection.
     *
     * @throws \PDOException             when the server is unreachable or refuses
     * @throws \InvalidArgumentException when the DSN cannot be parsed
     */
    public function databaseConnection(): \PDO
    {
        $parts = parse_url($this->databaseUrl);
        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException('DATABASE_URL is not a parseable URL.');
        }

        $dsn = \sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;options='-c statement_timeout=%d'",
            $parts['host'],
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/'),
            self::TIMEOUT_SECONDS * 1000,
        );

        // pdo_pgsql maps ATTR_TIMEOUT to libpq's connect_timeout (a
        // connect_timeout key inside the DSN string is ignored — measured);
        // the statement_timeout above is applied server-side per session.
        return new \PDO($dsn, $parts['user'] ?? null, $parts['pass'] ?? null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);
    }

    private function checkRedis(): bool
    {
        $parts = parse_url($this->redisUrl);
        if (false === $parts || !isset($parts['host'])) {
            return false;
        }

        try {
            $redis = new \Redis();
            // connect timeout and read timeout (for PING) are both bounded;
            // name resolution happens before either and is the resolver's.
            $connected = $redis->connect(
                $parts['host'],
                $parts['port'] ?? 6379,
                (float) self::TIMEOUT_SECONDS,
                null,
                0,
                (float) self::TIMEOUT_SECONDS,
            );
            if (!$connected) {
                return false;
            }
            if (isset($parts['pass']) && !$redis->auth($parts['pass'])) {
                return false;
            }

            return true === $redis->ping();
        } catch (\RedisException) {
            return false;
        }
    }
}
