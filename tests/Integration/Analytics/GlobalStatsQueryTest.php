<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Query\GlobalStatsQuery;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;

/** Spec analytics "Global statistics for administrators": the totals. */
#[CoversClass(GlobalStatsQuery::class)]
final class GlobalStatsQueryTest extends AnalyticsQueryTestCase
{
    public function testTotals(): void
    {
        $a = UserFactory::createOne();
        UserFactory::createMany(2);
        $a1 = LinkFactory::createOne(['owner' => $a]);
        $a2 = LinkFactory::new()->inactive()->create(['owner' => $a]);
        $b1 = LinkFactory::createOne(); // its owner is a fourth user
        self::click($a1, '2026-09-02T10:00:00Z', [], 5);
        self::click($a2, '2026-09-02T10:00:00Z', [], 3);
        self::click($b1, '2026-09-11T09:00:00Z', [], 4); // today
        self::click($b1, '2026-09-11T09:00:00Z', ['bot' => true], 1);

        $request = $this->request(null, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z');
        $totals = (new GlobalStatsQuery(self::connection()))->totals($request, $this->startOfToday());
        $withBots = (new GlobalStatsQuery(self::connection()))->totals($this->request(null, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z', includeBots: true), $this->startOfToday());

        self::assertSame([4, 3, 2, 12, 4], [$totals->totalUsers, $totals->totalLinks, $totals->activeLinks, $totals->totalClicks, $totals->clicksToday]);
        self::assertSame([13, 5], [$withBots->totalClicks, $withBots->clicksToday]);
    }
}
