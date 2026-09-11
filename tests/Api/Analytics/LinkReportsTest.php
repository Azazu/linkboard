<?php

declare(strict_types=1);

namespace App\Tests\Api\Analytics;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec analytics: "Report parameters and period", "Authorization boundary of
 * link reports", "Reports are computed by the database", "Bots are excluded
 * unless asked for" — over HTTP.
 */
#[CoversNothing]
final class LinkReportsTest extends AnalyticsApiTestCase
{
    private const array REPORTS = ['summary', 'timeseries', 'countries', 'devices', 'referrers', 'variants'];

    public function testOwnerAdminStrangerAndAnonymousOnEveryReport(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        UserFactory::createOne(['email' => 'b@example.com']);
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        self::click($link->getId(), '2026-09-02T10:00:00Z', ['country' => 'DE', 'variant' => 'A'], 2);

        $owner = $this->token($client, 'a@example.com');
        $admin = $this->token($client, 'admin@example.com');
        $stranger = $this->token($client, 'b@example.com');
        foreach (self::REPORTS as $report) {
            $uri = "/api/v1/links/{$link->getId()}/stats/$report";
            $body = $this->get($client, $owner, $uri);
            self::assertSame((string) $link->getId(), $body['linkId'], $report);
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00\z/', (string) $body['generatedAt'], $report);
            $this->get($client, $admin, $uri);

            $this->api($client, $stranger, 'GET', $uri);
            self::assertResponseStatusCodeSame(403, "$report as a stranger");
            self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

            $client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(401, "$report anonymous");
        }
    }

    public function testUnknownLinkIs404ForEveryone(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        foreach (['/api/v1/links/not-a-uuid/stats/summary', '/api/v1/links/0192b6f0-0000-7000-8000-000000000001/stats/summary'] as $uri) {
            $this->api($client, $token, 'GET', $uri);
            self::assertResponseStatusCodeSame(404, $uri);
            self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        }
    }

    public function testInactiveLinkKeepsItsReports(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::new()->inactive()->create(['owner' => $a]);
        self::click($link->getId(), '2026-09-02T10:00:00Z', [], 3);

        $body = $this->get($client, $this->token($client, 'a@example.com'), "/api/v1/links/{$link->getId()}/stats/summary");

        self::assertSame(3, $body['totalClicks']);
    }

    public function testDefaultsAreDayAlignedAndParametersAreEchoed(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $token = $this->token($client, 'a@example.com');
        $expectedTo = new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->setTime(0, 0)->modify('+1 day');

        $summary = $this->get($client, $token, "/api/v1/links/{$link->getId()}/stats/summary");
        self::assertSame($expectedTo->format('c'), $summary['to']);
        self::assertSame($expectedTo->modify('-30 days')->format('c'), $summary['from']);
        self::assertFalse($summary['includeBots']);
        self::assertSame(['linkId', 'from', 'to', 'includeBots', 'totalClicks', 'uniqueVisitors', 'firstClickAt', 'lastClickAt', 'clicksToday', 'clicksInPeriod', 'clicksInPreviousPeriod', 'deltaPercent', 'generatedAt'], array_keys($summary));
        self::assertNull($summary['firstClickAt']);
        self::assertNull($summary['deltaPercent']);

        $series = $this->get($client, $token, "/api/v1/links/{$link->getId()}/stats/timeseries?".self::PERIOD.'&granularity=hour&includeBots=true');
        self::assertSame(['2026-09-01T00:00:00+00:00', '2026-09-08T00:00:00+00:00', 'hour', true], [$series['from'], $series['to'], $series['granularity'], $series['includeBots']]);
        self::assertCount(168, $series['buckets']);

        $countries = $this->get($client, $token, "/api/v1/links/{$link->getId()}/stats/countries?limit=3");
        self::assertSame(3, $countries['limit']);
        self::assertSame([], $countries['items']);
        self::assertSame(0, $countries['total']);
    }

    public function testMalformedAndOutOfRangeParameters(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $token = $this->token($client, 'a@example.com');
        $base = "/api/v1/links/{$link->getId()}/stats/";

        foreach ([
            'summary?from=yesterday' => 'from',
            'timeseries?granularity=week' => 'granularity',
            'countries?limit=0' => 'limit',
            'countries?limit=51' => 'limit',
            'countries?limit=abc' => 'limit',
            'summary?includeBots=maybe' => 'includeBots',
            'summary?to=2026-09-01' => 'to',
        ] as $query => $parameter) {
            $this->api($client, $token, 'GET', $base.$query);
            $violations = $this->violations($client);
            self::assertCount(1, $violations, $query);
            self::assertSame($parameter, $violations[0]['propertyPath'], $query);
            self::assertNotSame('', $violations[0]['message']);
        }
    }

    public function testInconsistentParameters(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $token = $this->token($client, 'a@example.com');
        $base = "/api/v1/links/{$link->getId()}/stats/";

        foreach ([
            'summary?from=2026-09-08T00:00:00Z&to=2026-09-01T00:00:00Z' => 'from',
            'summary?from=2025-01-01T00:00:00Z&to=2026-03-01T00:00:00Z' => 'to',
            'timeseries?from=2026-08-01T00:00:00Z&to=2026-09-01T00:00:00Z&granularity=hour' => 'granularity',
        ] as $query => $parameter) {
            $this->api($client, $token, 'GET', $base.$query);
            $violations = $this->violations($client);
            self::assertCount(1, $violations, $query);
            self::assertSame($parameter, $violations[0]['propertyPath'], $query);
        }
    }

    public function testNumbersOverHttpAndTheBotsToggle(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $id = $link->getId();
        self::click($id, '2026-09-02T10:00:00Z', ['visitor' => 'v1', 'country' => 'DE', 'referer' => 'news.example.org'], 3);
        self::click($id, '2026-09-05T10:00:00Z', ['visitor' => 'v2', 'country' => 'US'], 2);
        self::click($id, '2026-09-05T11:00:00Z', ['visitor' => 'v3', 'country' => 'US', 'deviceType' => 'desktop', 'os' => 'Linux'], 2);
        self::click($id, '2026-09-06T11:00:00Z', ['visitor' => 'bot', 'bot' => true], 2);
        $token = $this->token($client, 'a@example.com');
        $base = "/api/v1/links/$id/stats/";

        $summary = $this->get($client, $token, $base.'summary?'.self::PERIOD);
        self::assertSame([7, 7, 3], [$summary['clicksInPeriod'], $summary['totalClicks'], $summary['uniqueVisitors']]);
        $withBots = $this->get($client, $token, $base.'summary?'.self::PERIOD.'&includeBots=true');
        self::assertSame([9, 9, 4], [$withBots['clicksInPeriod'], $withBots['totalClicks'], $withBots['uniqueVisitors']]);

        $series = $this->get($client, $token, $base.'timeseries?'.self::PERIOD);
        self::assertSame([0, 3, 0, 0, 4, 0, 0], array_column($series['buckets'], 'clicks'));
        self::assertSame([0, 3, 3, 3, 7, 7, 7], array_column($series['buckets'], 'cumulativeClicks'));
        self::assertSame('2026-09-01T00:00:00+00:00', $series['buckets'][0]['bucket']);
        self::assertSame(9, array_sum(array_column($this->get($client, $token, $base.'timeseries?'.self::PERIOD.'&includeBots=true')['buckets'], 'clicks')));

        $countries = $this->get($client, $token, $base.'countries?'.self::PERIOD);
        self::assertSame(7, $countries['total']);
        self::assertSame([['country' => 'US', 'clicks' => 4, 'share' => 57.1, 'rank' => 1], ['country' => 'DE', 'clicks' => 3, 'share' => 42.9, 'rank' => 2]], $countries['items']);

        $devices = $this->get($client, $token, $base.'devices?'.self::PERIOD);
        self::assertSame([['deviceType' => null, 'clicks' => 5, 'share' => 71.4], ['deviceType' => 'desktop', 'clicks' => 2, 'share' => 28.6]], $devices['byDeviceType']);
        self::assertSame([['os' => null, 'clicks' => 5, 'share' => 71.4], ['os' => 'Linux', 'clicks' => 2, 'share' => 28.6]], $devices['byOs']);

        $referrers = $this->get($client, $token, $base.'referrers?'.self::PERIOD.'&limit=1');
        self::assertSame(7, $referrers['total']);
        self::assertSame([['host' => 'direct', 'clicks' => 4, 'share' => 57.1, 'rank' => 1]], $referrers['items']);

        $variants = $this->get($client, $token, $base.'variants?'.self::PERIOD);
        self::assertSame([0, []], [$variants['total'], $variants['items']]);
    }

    public function testOpenApiDocumentsTheSixReportsAndTheirParameters(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs.json');
        self::assertResponseStatusCodeSame(200);
        $paths = $this->decode($client)['paths'];
        self::assertIsArray($paths);

        foreach (self::REPORTS as $report) {
            $path = "/api/v1/links/{id}/stats/$report";
            self::assertArrayHasKey($path, $paths, $report);
            $names = array_column($paths[$path]['get']['parameters'], 'name');
            self::assertContains('from', $names, $report);
            self::assertContains('to', $names, $report);
            self::assertContains('includeBots', $names, $report);
            self::assertSame('timeseries' === $report, \in_array('granularity', $names, true), $report);
            self::assertSame(\in_array($report, ['countries', 'referrers'], true), \in_array('limit', $names, true), $report);
        }
    }
}
