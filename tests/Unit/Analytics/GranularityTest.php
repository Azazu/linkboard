<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Report\Granularity;
use App\Analytics\Report\InvalidReportParameter;
use App\Analytics\Report\Period;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(Granularity::class)]
final class GranularityTest extends TestCase
{
    public function testUnitsAndSteps(): void
    {
        self::assertSame(['hour', '1 hour'], [Granularity::Hour->unit(), Granularity::Hour->step()]);
        self::assertSame(['day', '1 day'], [Granularity::Day->unit(), Granularity::Day->step()]);
        self::assertSame(Granularity::Hour, Granularity::from('hour'));
        self::assertNull(Granularity::tryFrom('week'));
    }

    public function testHourlyBucketsAreBoundedToFourteenDays(): void
    {
        $clock = new MockClock();
        $fourteen = Period::of('2026-09-01T00:00:00Z', '2026-09-15T00:00:00Z', $clock);
        $fifteen = Period::of('2026-09-01T00:00:00Z', '2026-09-15T00:00:01Z', $clock);

        Granularity::Hour->assertAllowedFor($fourteen);
        Granularity::Day->assertAllowedFor($fifteen);
        try {
            Granularity::Hour->assertAllowedFor($fifteen);
            self::fail('accepted');
        } catch (InvalidReportParameter $e) {
            self::assertSame('granularity', $e->parameter);
        }
    }
}
