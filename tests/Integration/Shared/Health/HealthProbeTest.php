<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Health;

use App\Shared\Health\HealthProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs against the real PostgreSQL and Redis of the test stack.
 */
#[CoversClass(HealthProbe::class)]
final class HealthProbeTest extends KernelTestCase
{
    public function testBothDependenciesReachable(): void
    {
        self::bootKernel();
        $probe = self::getContainer()->get(HealthProbe::class);

        $report = $probe->run();

        self::assertSame(['database' => true, 'redis' => true], $report->checks);
    }

    public function testUnreachableRedisIsReportedAsFailureWithinTheTimeout(): void
    {
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'];
        \assert(\is_string($databaseUrl));
        // 192.0.2.0/24 is TEST-NET-1 (RFC 5737): routable nowhere, so the
        // connect attempt hangs until the probe's own timeout fires.
        $probe = new HealthProbe($databaseUrl, 'redis://192.0.2.1:6379');

        $started = microtime(true);
        $report = $probe->run();
        $elapsed = microtime(true) - $started;

        self::assertSame(['database' => true, 'redis' => false], $report->checks);
        self::assertLessThan(HealthProbe::TIMEOUT_SECONDS + 1.5, $elapsed, 'the redis check must be bounded by its timeout');
    }

    public function testUnreachableDatabaseIsReportedAsFailure(): void
    {
        $redisUrl = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'];
        \assert(\is_string($redisUrl));
        $probe = new HealthProbe('postgresql://nobody:nothing@192.0.2.2:5432/nowhere?serverVersion=16', $redisUrl);

        $started = microtime(true);
        $report = $probe->run();
        $elapsed = microtime(true) - $started;

        self::assertSame(['database' => false, 'redis' => true], $report->checks);
        self::assertLessThan(HealthProbe::TIMEOUT_SECONDS + 1.5, $elapsed, 'the database check must be bounded by its timeout');
    }

    public function testMalformedUrlsAreFailuresNotExceptions(): void
    {
        $probe = new HealthProbe('not a url', '');

        self::assertSame(['database' => false, 'redis' => false], $probe->run()->checks);
    }
}
