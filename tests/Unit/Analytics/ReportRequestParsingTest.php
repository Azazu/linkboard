<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Api\ReportRequestFactory;
use App\Analytics\Report\Granularity;
use App\Analytics\Report\InvalidReportParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parsing raises the refusal naming the parameter that carried it, so a caller
 * that is not an API operation can put the message on that control (design
 * decision 2 of add-web-admin-and-stats). `fromRequest()` is the same parsing
 * plus API Platform's 422, which `tests/Api/Analytics` covers over HTTP.
 */
#[CoversClass(ReportRequestFactory::class)]
final class ReportRequestParsingTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'end before start' => [['from' => '2026-09-08T00:00:00Z', 'to' => '2026-09-01T00:00:00Z'], 'from'];
        yield 'longer than the maximum' => [['from' => '2024-01-01T00:00:00Z', 'to' => '2026-01-01T00:00:00Z'], 'to'];
        yield 'a bound that is not a date' => [['from' => 'yesterday'], 'from'];
        yield 'hourly over too long a period' => [['from' => '2026-06-01T00:00:00Z', 'to' => '2026-09-01T00:00:00Z', 'granularity' => 'hour'], 'granularity'];
        yield 'granularity that is neither' => [['granularity' => 'week'], 'granularity'];
        yield 'limit that is not a number' => [['limit' => 'ten'], 'limit'];
        yield 'limit out of range' => [['limit' => '500'], 'limit'];
        yield 'bots flag that is not a boolean' => [['includeBots' => 'perhaps'], 'includeBots'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('refusals')]
    public function testARefusedParameterNamesItself(array $query, string $parameter): void
    {
        try {
            $this->factory()->parse(Request::create('/', 'GET', $query), null, withGranularity: true, withLimit: true);
            self::fail('the parameter should have been refused');
        } catch (InvalidReportParameter $e) {
            self::assertSame($parameter, $e->parameter);
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testParametersOutsideTheReportAreNotParsedAtAll(): void
    {
        // the flags say which parameters the operation takes; the rest are
        // ignored rather than refused, exactly as the API behaves today
        $request = $this->factory()->parse(Request::create('/', 'GET', ['granularity' => 'week', 'limit' => 'ten']), null);

        self::assertSame(Granularity::Day, $request->granularity);
        self::assertSame(10, $request->limit);
    }

    private function factory(): ReportRequestFactory
    {
        return new ReportRequestFactory(new MockClock(new \DateTimeImmutable('2026-09-11T14:05:00Z')));
    }
}
