<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Query\LinkSummaryQuery;
use App\Analytics\Query\Sql;
use PHPUnit\Framework\Attributes\CoversClass;

/** Spec analytics "Summary report" and "Bots are excluded unless asked for". */
#[CoversClass(LinkSummaryQuery::class)]
#[CoversClass(Sql::class)]
final class LinkSummaryQueryTest extends AnalyticsQueryTestCase
{
    public function testNumbers(): void
    {
        $link = $this->link();
        // period 2026-09-01..08: 4 clicks, 2 visitors
        self::click($link, '2026-09-02T10:00:00Z', ['visitor' => 'a'], 2);
        self::click($link, '2026-09-05T10:00:00Z', ['visitor' => 'b'], 2);
        // previous period 2026-08-25..09-01: 2 clicks, 1 visitor
        self::click($link, '2026-08-30T10:00:00Z', ['visitor' => 'c'], 2);
        // outside: one in July, one today
        self::click($link, '2026-07-01T09:00:00Z', ['visitor' => 'd']);
        self::click($link, '2026-09-11T13:00:00Z', ['visitor' => 'a']);
        // another link's click never counts
        self::click($this->link(), '2026-09-02T10:00:00Z', ['visitor' => 'a']);

        $figures = $this->query()->figures($this->request($link, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'), $this->startOfToday());

        self::assertSame(4, $figures->clicksInPeriod);
        self::assertSame(2, $figures->clicksInPreviousPeriod);
        self::assertSame(100.0, $figures->deltaPercent);
        self::assertSame(1, $figures->clicksToday);
        self::assertSame(8, $figures->totalClicks);
        self::assertSame(4, $figures->uniqueVisitors);
        self::assertSame('2026-07-01T09:00:00+00:00', $figures->firstClickAt?->format('c'));
        self::assertSame('2026-09-11T13:00:00+00:00', $figures->lastClickAt?->format('c'));
    }

    public function testDeltaIsNullWithoutAPreviousPeriodAndRounded(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', [], 3);

        $figures = $this->query()->figures($this->request($link, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'), $this->startOfToday());
        self::assertNull($figures->deltaPercent);
        self::assertSame(0, $figures->clicksInPreviousPeriod);

        self::click($link, '2026-08-30T10:00:00Z', [], 7); // 3 vs 7 → -57.142… → -57.1
        $figures = $this->query()->figures($this->request($link, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'), $this->startOfToday());
        self::assertSame(-57.1, $figures->deltaPercent);
    }

    public function testLinkWithoutClicks(): void
    {
        $figures = $this->query()->figures($this->request($this->link(), '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'), $this->startOfToday());

        self::assertSame([0, 0, 0, 0, 0], [$figures->totalClicks, $figures->uniqueVisitors, $figures->clicksToday, $figures->clicksInPeriod, $figures->clicksInPreviousPeriod]);
        self::assertNull($figures->firstClickAt);
        self::assertNull($figures->lastClickAt);
        self::assertNull($figures->deltaPercent);
    }

    public function testBotsAreExcludedUnlessAskedFor(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', [], 8);
        self::click($link, '2026-09-02T11:00:00Z', ['bot' => true], 2);

        $human = $this->query()->figures($this->request($link, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'), $this->startOfToday());
        $all = $this->query()->figures($this->request($link, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z', includeBots: true), $this->startOfToday());

        self::assertSame([8, 8], [$human->clicksInPeriod, $human->totalClicks]);
        self::assertSame([10, 10], [$all->clicksInPeriod, $all->totalClicks]);
    }

    private function query(): LinkSummaryQuery
    {
        return new LinkSummaryQuery(self::connection());
    }
}
