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
        $parts = parse_url($this->databaseUrl);
        if (false === $parts || !isset($parts['host'])) {
            return false;
        }

        $dsn = \sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=%d',
            $parts['host'],
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/'),
            self::TIMEOUT_SECONDS,
        );

        try {
            $pdo = new \PDO($dsn, $parts['user'] ?? null, $parts['pass'] ?? null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            return 1 === (int) $pdo->query('SELECT 1')->fetchColumn();
        } catch (\PDOException) {
            return false;
        }
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
