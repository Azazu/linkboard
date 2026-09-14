<?php

declare(strict_types=1);

namespace App\Analytics\Report;

use App\Analytics\Api\LinkCountriesReport;
use App\Analytics\Api\LinkDevicesReport;
use App\Analytics\Api\LinkReferrersReport;
use App\Analytics\Api\LinkSummaryReport;
use App\Analytics\Api\LinkTimeseriesReport;
use App\Analytics\Api\LinkVariantsReport;
use App\Analytics\Cache\ReportCache;
use App\Analytics\Query\BreakdownQuery;
use App\Analytics\Query\LinkSummaryQuery;
use App\Analytics\Query\TimeseriesQuery;
use Symfony\Component\Clock\ClockInterface;

/**
 * The six reports of one link, computed once and reached from both presenters
 * — the API's state provider and the statistics page (design decision 1 of
 * add-web-admin-and-stats). Both get the same object out of the same cache
 * entry, so they cannot disagree about a number or about `generatedAt`.
 *
 * Each method reduces the request it is handed to the parameters its report
 * actually uses before keying on it. That reduction used to live in the
 * provider's flags; it lives here because a page has one set of controls
 * feeding six reports, and handing that request on unchanged would write six
 * keys the API never writes.
 */
final readonly class LinkReports
{
    public function __construct(
        private ReportCache $cache,
        private ClockInterface $clock,
        private LinkSummaryQuery $summary,
        private TimeseriesQuery $timeseries,
        private BreakdownQuery $breakdown,
    ) {
    }

    public function summary(ReportRequest $request): LinkSummaryReport
    {
        $for = $request->reducedTo(withGranularity: false, withLimit: false);

        return $this->cache->remember(
            $for->cacheKey('summary'),
            [$for->cacheTag()],
            fn (): LinkSummaryReport => LinkSummaryReport::of($for, $this->summary->figures($for, $this->startOfToday()), $this->now()),
        );
    }

    public function timeseries(ReportRequest $request): LinkTimeseriesReport
    {
        $for = $request->reducedTo(withGranularity: true, withLimit: false);

        return $this->cache->remember(
            $for->cacheKey('timeseries'),
            [$for->cacheTag()],
            fn (): LinkTimeseriesReport => LinkTimeseriesReport::of($for, $this->timeseries->buckets($for), $this->now()),
        );
    }

    public function countries(ReportRequest $request): LinkCountriesReport
    {
        $for = $request->reducedTo(withGranularity: false, withLimit: true);

        return $this->cache->remember(
            $for->cacheKey('countries'),
            [$for->cacheTag()],
            fn (): LinkCountriesReport => LinkCountriesReport::of($for, $this->breakdown->countries($for), $this->now()),
        );
    }

    public function devices(ReportRequest $request): LinkDevicesReport
    {
        $for = $request->reducedTo(withGranularity: false, withLimit: false);

        return $this->cache->remember(
            $for->cacheKey('devices'),
            [$for->cacheTag()],
            fn (): LinkDevicesReport => LinkDevicesReport::of($for, $this->breakdown->devices($for), $this->now()),
        );
    }

    public function referrers(ReportRequest $request): LinkReferrersReport
    {
        $for = $request->reducedTo(withGranularity: false, withLimit: true);

        return $this->cache->remember(
            $for->cacheKey('referrers'),
            [$for->cacheTag()],
            fn (): LinkReferrersReport => LinkReferrersReport::of($for, $this->breakdown->referrers($for), $this->now()),
        );
    }

    public function variants(ReportRequest $request): LinkVariantsReport
    {
        $for = $request->reducedTo(withGranularity: false, withLimit: false);

        return $this->cache->remember(
            $for->cacheKey('variants'),
            [$for->cacheTag()],
            fn (): LinkVariantsReport => LinkVariantsReport::of($for, $this->breakdown->variants($for), $this->now()),
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
