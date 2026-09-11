<?php

declare(strict_types=1);

namespace App\Tests\Api\Analytics;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\StatementRecorder;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Spec analytics "Global statistics for administrators" — over HTTP. */
#[CoversNothing]
final class AdminStatsTest extends AnalyticsApiTestCase
{
    private const array REPORTS = ['summary', 'timeseries', 'top-links'];

    public function testSummaryTopLinksAndTimeseries(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $b = UserFactory::createOne(['email' => 'b@example.com']);
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $a1 = LinkFactory::createOne(['owner' => $a, 'slug' => 'a-one']);
        $a2 = LinkFactory::new()->inactive()->create(['owner' => $a, 'slug' => 'a-two']);
        $b1 = LinkFactory::createOne(['owner' => $b, 'slug' => 'b-one']);
        self::click($a1->getId(), '2026-09-02T10:00:00Z', ['visitor' => 'x'], 5);
        self::click($a2->getId(), '2026-09-02T10:00:00Z', [], 3);
        self::click($b1->getId(), '2026-09-05T10:00:00Z', [], 4);
        self::click($b1->getId(), '2026-09-05T10:00:00Z', ['bot' => true], 1);
        $admin = $this->token($client, 'admin@example.com');

        $summary = $this->get($client, $admin, '/api/v1/admin/stats/summary');
        self::assertSame([3, 3, 2, 12, 0, false], [$summary['totalUsers'], $summary['totalLinks'], $summary['activeLinks'], $summary['totalClicks'], $summary['clicksToday'], $summary['includeBots']]);
        self::assertSame(13, $this->get($client, $admin, '/api/v1/admin/stats/summary?includeBots=true')['totalClicks']);
        self::assertSame(['includeBots', 'totalUsers', 'totalLinks', 'activeLinks', 'totalClicks', 'clicksToday', 'generatedAt'], array_keys($summary), 'no period on the summary');
        $ignored = $this->get($client, $admin, '/api/v1/admin/stats/summary?from=yesterday&to=2026');
        self::assertSame($summary['totalClicks'], $ignored['totalClicks'], 'from/to are ignored by the summary, not validated');

        $top = $this->get($client, $admin, '/api/v1/admin/stats/top-links?'.self::PERIOD.'&limit=2');
        self::assertSame(12, $top['total']);
        self::assertSame([
            ['linkId' => (string) $a1->getId(), 'slug' => 'a-one', 'ownerId' => (string) $a->getId(), 'clicks' => 5, 'uniqueVisitors' => 1, 'rank' => 1],
            ['linkId' => (string) $b1->getId(), 'slug' => 'b-one', 'ownerId' => (string) $b->getId(), 'clicks' => 4, 'uniqueVisitors' => 4, 'rank' => 2],
        ], $top['items']);

        $series = $this->get($client, $admin, '/api/v1/admin/stats/timeseries?'.self::PERIOD);
        self::assertSame([0, 8, 0, 0, 4, 0, 0], array_column($series['buckets'], 'clicks'));
        self::assertSame([0, 8, 8, 8, 12, 12, 12], array_column($series['buckets'], 'cumulativeClicks'));
        self::assertSame(['bucket', 'clicks', 'cumulativeClicks'], array_keys($series['buckets'][0]), 'no unique visitors in the global timeseries');
        self::assertSame('day', $series['granularity']);

        StatementRecorder::reset();
        $this->get($client, $admin, '/api/v1/admin/stats/timeseries?'.self::PERIOD);
        self::assertSame([], array_filter(StatementRecorder::statements(), static fn (string $sql): bool => (bool) preg_match('/\bclicks\b/i', $sql)), 'served from the cache');
    }

    public function testRegularUserAndAnonymousAreRefused(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        foreach (self::REPORTS as $report) {
            $this->api($client, $token, 'GET', "/api/v1/admin/stats/$report");
            self::assertResponseStatusCodeSame(403, $report);
            self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        }
        $client->request('GET', '/api/v1/admin/stats/summary', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOpenApiDocumentsTheThreeAdminPaths(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs.json');
        $paths = $this->decode($client)['paths'];
        self::assertIsArray($paths);
        foreach (self::REPORTS as $report) {
            self::assertArrayHasKey("/api/v1/admin/stats/$report", $paths, $report);
        }
    }
}
