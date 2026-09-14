<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Report\GlobalReports;
use App\Analytics\Report\Granularity;
use App\Analytics\Report\LinkReports;
use App\Link\Entity\Link;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The report services of design decision 1 (add-web-admin-and-stats): the one
 * place a report is computed. Here the cache behaviour they inherit is
 * asserted directly — a second call within the time-to-live answers the same
 * object, `generatedAt` included, rather than recomputing it. That the API and
 * a page land on that same entry is `tests/Api/Analytics/CacheSharedWithPagesTest`.
 */
#[CoversNothing]
final class ReportServicesTest extends AnalyticsQueryTestCase
{
    protected function tearDown(): void
    {
        $pool = self::getContainer()->get('cache.reports');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }
        parent::tearDown();
    }

    public function testASecondCallWithinTheTimeToLiveAnswersTheSameReport(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'DE'], 4);
        $reports = $this->linkReports();
        $request = $this->request($link, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z');

        $first = $reports->summary($request);
        self::click($link, '2026-09-03T10:00:00Z', [], 1);
        $second = $reports->summary($request);

        self::assertSame(4, $first->clicksInPeriod);
        self::assertEquals($first, $second, 'within the time-to-live the entry stands, the new click included');
        self::assertSame($first->generatedAt->format(\DateTimeInterface::ATOM), $second->generatedAt->format(\DateTimeInterface::ATOM));
    }

    public function testEachReportKeepsItsOwnEntry(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'DE'], 2);
        $reports = $this->linkReports();
        $request = $this->request($link, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z');

        self::assertSame(2, $reports->summary($request)->clicksInPeriod);
        self::assertSame(2, $reports->countries($request)->total, 'a sibling report is its own entry, not the summary’s');
        self::assertCount(7, $reports->timeseries($request)->buckets);
    }

    public function testTheGlobalSummaryIgnoresTheChosenPeriod(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', [], 3);
        $global = $this->globalReports();

        $chosen = $global->summary($this->request(null, '2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z', Granularity::Hour, 7));
        $another = $global->summary($this->request(null, '2026-08-01T00:00:00Z', '2026-08-15T00:00:00Z'));

        self::assertSame(3, $chosen->totalClicks, 'the summary is all-time');
        self::assertEquals($chosen, $another, 'both reduce to the same period-less entry');
    }

    protected function link(): Link
    {
        return parent::link();
    }

    private function linkReports(): LinkReports
    {
        $reports = self::getContainer()->get(LinkReports::class);
        self::assertInstanceOf(LinkReports::class, $reports);

        return $reports;
    }

    private function globalReports(): GlobalReports
    {
        $reports = self::getContainer()->get(GlobalReports::class);
        self::assertInstanceOf(GlobalReports::class, $reports);

        return $reports;
    }
}
