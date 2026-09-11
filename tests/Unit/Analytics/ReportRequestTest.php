<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Report\Granularity;
use App\Analytics\Report\InvalidReportParameter;
use App\Analytics\Report\Period;
use App\Analytics\Report\ReportRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** Design decision 7: the cache key names the report and every effective parameter. */
#[CoversClass(ReportRequest::class)]
final class ReportRequestTest extends TestCase
{
    public function testKeyIsDeterministicAndDistinctPerParameter(): void
    {
        $clock = new MockClock();
        $link = Uuid::v7();
        $period = Period::of('2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z', $clock);
        $base = new ReportRequest($link, $period);

        self::assertSame($base->cacheKey('summary'), (new ReportRequest($link, $period))->cacheKey('summary'));
        self::assertMatchesRegularExpression('/^summary\.[0-9a-f]{40}\z/', $base->cacheKey('summary'));
        $variants = [
            (new ReportRequest($link, $period))->cacheKey('timeseries'),
            (new ReportRequest(Uuid::v7(), $period))->cacheKey('summary'),
            (new ReportRequest(null, $period))->cacheKey('summary'),
            (new ReportRequest($link, Period::of('2026-09-01T00:00:00Z', '2026-09-09T00:00:00Z', $clock)))->cacheKey('summary'),
            (new ReportRequest($link, $period, Granularity::Hour))->cacheKey('summary'),
            (new ReportRequest($link, $period, limit: 11))->cacheKey('summary'),
            (new ReportRequest($link, $period, includeBots: true))->cacheKey('summary'),
        ];
        self::assertCount(8, array_unique([$base->cacheKey('summary'), ...$variants]));
        self::assertSame('link-'.$link->toRfc4122(), $base->cacheTag());
        self::assertSame('global', (new ReportRequest(null, $period))->cacheTag());
    }

    public function testLimitAndGranularityAreValidatedTogether(): void
    {
        $clock = new MockClock();
        $short = Period::of('2026-09-01T00:00:00Z', '2026-09-02T00:00:00Z', $clock);
        $long = Period::of('2026-08-01T00:00:00Z', '2026-09-01T00:00:00Z', $clock);

        self::assertSame(50, (new ReportRequest(null, $short, limit: 50))->limit);
        foreach ([0, 51] as $limit) {
            try {
                new ReportRequest(null, $short, limit: $limit);
                self::fail('accepted');
            } catch (InvalidReportParameter $e) {
                self::assertSame('limit', $e->parameter);
            }
        }
        try {
            new ReportRequest(null, $long, Granularity::Hour);
            self::fail('accepted');
        } catch (InvalidReportParameter $e) {
            self::assertSame('granularity', $e->parameter);
        }
    }
}
