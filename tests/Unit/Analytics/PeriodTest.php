<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Report\InvalidReportParameter;
use App\Analytics\Report\Period;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Spec analytics "Report parameters and period" — the period rules. */
#[CoversClass(Period::class)]
#[CoversClass(InvalidReportParameter::class)]
final class PeriodTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable('2026-09-11T14:05:00Z'));
    }

    public function testDefaultsAreTheCurrentUtcDayAndTheTwentyNineBefore(): void
    {
        $period = Period::defaults($this->clock);

        self::assertSame('2026-08-13T00:00:00+00:00', $period->from->format('c'));
        self::assertSame('2026-09-12T00:00:00+00:00', $period->to->format('c'));
        self::assertSame(30.0, $period->days());
    }

    public function testDefaultsAlignToUtcWhateverTheClockZone(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-09-11T01:30:00+03:00')); // 2026-09-10T22:30Z

        self::assertSame('2026-09-11T00:00:00+00:00', Period::defaults($clock)->to->format('c'));
    }

    public function testExplicitBoundsAreParsedToUtcInBothSpellings(): void
    {
        $period = Period::of('2026-09-01T02:00:00+02:00', '2026-09-08T00:00:00Z', $this->clock);

        self::assertSame('2026-09-01T00:00:00+00:00', $period->from->format('c'));
        self::assertSame('2026-09-08T00:00:00+00:00', $period->to->format('c'));
        self::assertSame('UTC', $period->from->getTimezone()->getName());
    }

    public function testOneBoundKeepsTheOtherDefault(): void
    {
        $onlyFrom = Period::of('2026-09-01T00:00:00Z', null, $this->clock);
        $onlyTo = Period::of(null, '2026-09-08T00:00:00Z', $this->clock);

        self::assertSame('2026-09-12T00:00:00+00:00', $onlyFrom->to->format('c'));
        self::assertSame('2026-08-09T00:00:00+00:00', $onlyTo->from->format('c'));
    }

    public function testMalformedBoundNamesItsParameter(): void
    {
        foreach (['yesterday', '2026-09-01 00:00:00', '2026-09-01T00:00:00.5Z', ['2026-09-01T00:00:00Z'], 7] as $bad) {
            try {
                Period::of($bad, null, $this->clock);
                self::fail('from was accepted');
            } catch (InvalidReportParameter $e) {
                self::assertSame('from', $e->parameter);
            }
            try {
                Period::of(null, $bad, $this->clock);
                self::fail('to was accepted');
            } catch (InvalidReportParameter $e) {
                self::assertSame('to', $e->parameter);
            }
        }
    }

    public function testFromMustBeBeforeTo(): void
    {
        foreach (['2026-09-08T00:00:00Z', '2026-09-01T00:00:00Z'] as $from) {
            try {
                Period::of($from, '2026-09-01T00:00:00Z', $this->clock);
                self::fail('accepted');
            } catch (InvalidReportParameter $e) {
                self::assertSame('from', $e->parameter);
            }
        }
    }

    public function testPeriodIsBoundedTo366Days(): void
    {
        self::assertSame(366.0, Period::of('2025-01-01T00:00:00Z', '2026-01-02T00:00:00Z', $this->clock)->days());

        $this->expectException(InvalidReportParameter::class);
        $this->expectExceptionMessage('366 days');
        try {
            Period::of('2025-01-01T00:00:00Z', '2026-01-02T00:00:01Z', $this->clock);
        } catch (InvalidReportParameter $e) {
            self::assertSame('to', $e->parameter);
            throw $e;
        }
    }

    public function testPreviousPeriodHasTheSameLengthAndEndsAtFrom(): void
    {
        $previous = Period::of('2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z', $this->clock)->previous();

        self::assertSame('2026-08-25T00:00:00+00:00', $previous->from->format('c'));
        self::assertSame('2026-09-01T00:00:00+00:00', $previous->to->format('c'));
    }
}
