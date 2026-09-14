<?php

declare(strict_types=1);

namespace App\Analytics\Report;

use App\Analytics\Api\AdminSummaryReport;
use App\Analytics\Api\AdminTimeseriesReport;
use App\Analytics\Api\AdminTopLinksReport;
use App\Analytics\Cache\ReportCache;
use App\Analytics\Query\BreakdownQuery;
use App\Analytics\Query\GlobalStatsQuery;
use App\Analytics\Query\TimeseriesQuery;
use Symfony\Component\Clock\ClockInterface;

/**
 * The three service-wide reports, computed once for the API's state provider
 * and the administrative statistics page alike (design decision 1 of
 * add-web-admin-and-stats).
 *
 * The summary has no period of its own: it is all-time plus today, so it
 * reduces to the default period whatever the caller chose, which is what
 * keeps one cache entry serving both presenters.
 */
final readonly class GlobalReports
{
    public function __construct(
        private ReportCache $cache,
        private ClockInterface $clock,
        private GlobalStatsQuery $totals,
        private TimeseriesQuery $timeseries,
        private BreakdownQuery $breakdown,
    ) {
    }

    public function summary(ReportRequest $request): AdminSummaryReport
    {
        $for = $request->reducedTo(withGranularity: false, withLimit: false, period: Period::defaults($this->clock));

        return $this->cache->remember(
            $for->cacheKey('admin-summary'),
            [$for->cacheTag()],
            fn (): AdminSummaryReport => AdminSummaryReport::of($for, $this->totals->totals($for, $this->startOfToday()), $this->now()),
        );
    }

    public function timeseries(ReportRequest $request): AdminTimeseriesReport
    {
        $for = $request->reducedTo(withGranularity: true, withLimit: false);

        return $this->cache->remember(
            $for->cacheKey('admin-timeseries'),
            [$for->cacheTag()],
            fn (): AdminTimeseriesReport => AdminTimeseriesReport::of($for, $this->timeseries->clickBuckets($for), $this->now()),
        );
    }

    public function topLinks(ReportRequest $request): AdminTopLinksReport
    {
        $for = $request->reducedTo(withGranularity: false, withLimit: true);

        return $this->cache->remember(
            $for->cacheKey('admin-toplinks'),
            [$for->cacheTag()],
            fn (): AdminTopLinksReport => AdminTopLinksReport::of($for, $this->breakdown->topLinks($for), $this->now()),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    private function startOfToday(): \DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }
}
