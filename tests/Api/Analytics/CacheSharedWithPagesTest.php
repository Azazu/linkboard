<?php

declare(strict_types=1);

namespace App\Tests\Api\Analytics;

use App\Analytics\Report\GlobalReports;
use App\Analytics\Report\LinkReports;
use App\Analytics\Report\Period;
use App\Analytics\Report\ReportRequest;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\StatementRecorder;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Clock\ClockInterface;

/**
 * One computation per report, reached from both presenters (design decision 1
 * of add-web-admin-and-stats).
 *
 * The page has one set of controls feeding nine reports, so it hands the same
 * request — an explicit period, hourly buckets and a non-default limit — to
 * every service method, while the API normalizes those parameters per report.
 * If the services did not reduce the request the same way, each page call
 * would write a key the API never writes: the same numbers computed twice.
 * The proof is that a service call right after the API's request executes no
 * query over the click records and answers the very `generatedAt` the API sent.
 */
#[CoversNothing]
final class CacheSharedWithPagesTest extends AnalyticsApiTestCase
{
    private const string QUERY = self::PERIOD.'&granularity=hour&limit=7';

    public function testEveryLinkReportSharesItsEntryWithThePage(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        self::click($link->getId(), '2026-09-02T10:00:00Z', ['country' => 'DE', 'deviceType' => 'smartphone', 'os' => 'iOS', 'referer' => 'https://news.example.com/x', 'variant' => 'A', 'resolvedBy' => 'variant'], 3);
        $token = $this->token($client, 'a@example.com');

        $reports = self::getContainer()->get(LinkReports::class);
        self::assertInstanceOf(LinkReports::class, $reports);
        $asThePageAsks = new ReportRequest($link->getId(), $this->period(), \App\Analytics\Report\Granularity::Hour, 7);

        foreach (['summary', 'timeseries', 'countries', 'devices', 'referrers', 'variants'] as $report) {
            $body = $this->get($client, $token, "/api/v1/links/{$link->getId()}/stats/$report?".self::QUERY);

            StatementRecorder::reset();
            $fromThePage = $reports->{$report}($asThePageAsks);

            self::assertSame([], self::clickStatements(), "$report: the page must reuse the API's entry, not compute its own");
            self::assertSame($body['generatedAt'], $fromThePage->generatedAt->format(\DateTimeInterface::ATOM), $report);
        }
    }

    public function testEveryGlobalReportSharesItsEntryWithThePage(): void
    {
        $client = self::createClient();
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        self::click($link->getId(), '2026-09-02T10:00:00Z', [], 3);
        $token = $this->token($client, 'admin@example.com');

        $reports = self::getContainer()->get(GlobalReports::class);
        self::assertInstanceOf(GlobalReports::class, $reports);
        // the very request the administrative page builds: a chosen period, hourly
        // buckets and a limit, handed to the summary as well — which has no period
        $asThePageAsks = new ReportRequest(null, $this->period(), \App\Analytics\Report\Granularity::Hour, 7);

        foreach (['summary' => 'summary', 'timeseries' => 'timeseries', 'top-links' => 'topLinks'] as $path => $method) {
            $body = $this->get($client, $token, "/api/v1/admin/stats/$path?".self::QUERY);

            StatementRecorder::reset();
            $fromThePage = $reports->{$method}($asThePageAsks);

            self::assertSame([], self::clickStatements(), "$path: the page must reuse the API's entry, not compute its own");
            self::assertSame($body['generatedAt'], $fromThePage->generatedAt->format(\DateTimeInterface::ATOM), $path);
        }
    }

    private function period(): Period
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return Period::of(self::FROM, self::TO, $clock);
    }

    /**
     * @return list<string>
     */
    private static function clickStatements(): array
    {
        return array_values(array_filter(StatementRecorder::statements(), static fn (string $sql): bool => (bool) preg_match('/\bclicks\b/i', $sql)));
    }
}
