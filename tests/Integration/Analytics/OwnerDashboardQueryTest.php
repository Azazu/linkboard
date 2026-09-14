<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Dto\ClickBucket;
use App\Analytics\Query\OwnerDashboardQuery;
use App\Analytics\Report\Period;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Spec web-ui — "The dashboard shows the signed-in user's own figures":
 * two owners' numbers never mix, bots stay out, empty days are zeros and the
 * period is half-open, as everywhere else in the analytics capability.
 */
#[CoversClass(OwnerDashboardQuery::class)]
final class OwnerDashboardQueryTest extends AnalyticsQueryTestCase
{
    public function testTheFiguresCountOnlyTheOwnersLinks(): void
    {
        $ann = UserFactory::createOne();
        $bea = UserFactory::createOne();
        $annsLink = LinkFactory::createOne(['owner' => $ann]);
        $annsOther = LinkFactory::createOne(['owner' => $ann]);
        LinkFactory::new(['owner' => $ann])->inactive()->create();
        $beasLink = LinkFactory::createOne(['owner' => $bea]);

        self::click($annsLink, '2026-09-11T09:00:00Z', ['visitor' => 'a'], 2);
        self::click($annsOther, '2026-09-10T09:00:00Z', ['visitor' => 'a']);
        self::click($annsOther, '2026-09-10T10:00:00Z', ['visitor' => 'b']);
        self::click($beasLink, '2026-09-11T09:00:00Z', ['visitor' => 'c'], 5);

        $totals = $this->query()->totals($ann->getId(), $this->startOfToday());

        self::assertSame(3, $totals->links);
        self::assertSame(4, $totals->clicks, "Bea's five clicks are not Ann's");
        self::assertSame(2, $totals->uniqueVisitors, 'one visitor across two links counts once');
        self::assertSame(2, $totals->clicksToday);
    }

    public function testAnOwnerWithoutClicksGetsZeros(): void
    {
        $ann = UserFactory::createOne();
        LinkFactory::createOne(['owner' => $ann]);

        $totals = $this->query()->totals($ann->getId(), $this->startOfToday());

        self::assertSame(1, $totals->links);
        self::assertSame(1, $totals->activeLinks);
        self::assertSame(0, $totals->clicks);
        self::assertSame(0, $totals->uniqueVisitors);
        self::assertSame(0, $totals->clicksToday);
    }

    public function testBotsAreExcludedUnlessAskedFor(): void
    {
        $ann = UserFactory::createOne();
        $link = LinkFactory::createOne(['owner' => $ann]);
        self::click($link, '2026-09-11T09:00:00Z', ['visitor' => 'human']);
        self::click($link, '2026-09-11T09:30:00Z', ['visitor' => 'crawler', 'bot' => true], 4);

        self::assertSame(1, $this->query()->totals($ann->getId(), $this->startOfToday())->clicks);
        self::assertSame(5, $this->query()->totals($ann->getId(), $this->startOfToday(), includeBots: true)->clicks);

        $days = $this->query()->daily($ann->getId(), $this->period('2026-09-11T00:00:00Z', '2026-09-12T00:00:00Z'));
        self::assertSame([1], array_map(static fn (ClickBucket $b): int => $b->clicks, $days));
    }

    public function testTheDailySeriesFillsGapsAndAccumulatesOverTheOwnersLinks(): void
    {
        $ann = UserFactory::createOne();
        $bea = UserFactory::createOne();
        $first = LinkFactory::createOne(['owner' => $ann]);
        $second = LinkFactory::createOne(['owner' => $ann]);
        LinkFactory::createOne(['owner' => $bea]);

        self::click($first, '2026-09-02T10:00:00Z', ['visitor' => 'a'], 2);
        self::click($second, '2026-09-02T23:59:59Z', ['visitor' => 'b']);
        self::click($first, '2026-09-05T00:00:00Z', ['visitor' => 'a']);
        self::click($first, '2026-09-08T00:00:00Z'); // `to` is exclusive
        self::click($first, '2026-08-31T23:59:59Z'); // before `from`
        self::click(LinkFactory::createOne(['owner' => $bea]), '2026-09-02T10:00:00Z', ['visitor' => 'z'], 9);

        $days = $this->query()->daily($ann->getId(), $this->period('2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z'));

        self::assertCount(7, $days);
        self::assertSame('2026-09-01T00:00:00+00:00', $days[0]->bucket->format('c'));
        self::assertSame([0, 3, 0, 0, 1, 0, 0], array_map(static fn (ClickBucket $b): int => $b->clicks, $days));
        self::assertSame([0, 3, 3, 3, 4, 4, 4], array_map(static fn (ClickBucket $b): int => $b->cumulativeClicks, $days));
    }

    private function query(): OwnerDashboardQuery
    {
        return new OwnerDashboardQuery(self::connection());
    }

    private function period(string $from, string $to): Period
    {
        return Period::of($from, $to, $this->clock);
    }
}
