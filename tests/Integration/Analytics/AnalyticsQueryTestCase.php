<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Report\Granularity;
use App\Analytics\Report\Period;
use App\Analytics\Report\ReportRequest;
use App\Link\Entity\Link;
use App\Tests\Factory\LinkFactory;
use App\Tests\Fixture\ClickRows;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/** Real PostgreSQL, rows through the DBAL fixture, a fixed clock. */
abstract class AnalyticsQueryTestCase extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /** 2026-09-11T14:05:00Z — "today" is 2026-09-11 UTC. */
    protected const string NOW = '2026-09-11T14:05:00Z';

    protected MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
    }

    protected static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    protected function link(): Link
    {
        return LinkFactory::createOne();
    }

    /**
     * @param array{country?: ?string, deviceType?: ?string, os?: ?string, browser?: ?string, bot?: bool, referer?: ?string, visitor?: string, variant?: ?string, resolvedBy?: string} $facts
     */
    protected static function click(Link|Uuid $link, string $at, array $facts = [], int $count = 1): void
    {
        ClickRows::many(self::connection(), $link instanceof Link ? $link->getId() : $link, $count, $at, $facts);
    }

    protected function request(Link|Uuid|null $link, string $from, string $to, Granularity $granularity = Granularity::Day, int $limit = 10, bool $includeBots = false): ReportRequest
    {
        $id = $link instanceof Link ? $link->getId() : $link;

        return new ReportRequest($id, Period::of($from, $to, $this->clock), $granularity, $limit, $includeBots);
    }

    protected function startOfToday(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
    }
}
