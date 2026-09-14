<?php

declare(strict_types=1);

namespace App\Tests\Web\Stats;

use App\Tests\Factory\LinkFactory;
use App\Tests\Fixture\ClickRows;
use App\Tests\Web\WebPageTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Spec web-ui — "A link's statistics page shows every report the analytics
 * capability defines" and the no-JavaScript rule that every charted series is
 * also a table.
 */
#[CoversNothing]
final class LinkStatsTest extends WebPageTestCase
{
    private const string PERIOD = 'from=2026-09-01&to=2026-09-08';

    public function testEveryReportIsOnThePage(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        $connection = self::connection();
        ClickRows::many($connection, $link->getId(), 3, '2026-09-02T10:00:00Z', ['country' => 'DE', 'deviceType' => 'smartphone', 'os' => 'iOS', 'referer' => 'https://news.example.com/a', 'visitor' => 'v1', 'variant' => 'A', 'resolvedBy' => 'variant']);
        ClickRows::many($connection, $link->getId(), 2, '2026-09-04T10:00:00Z', ['country' => 'FR', 'deviceType' => 'desktop', 'os' => 'Windows', 'referer' => null, 'visitor' => 'v2', 'variant' => 'B', 'resolvedBy' => 'variant']);

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/links/'.$link->getId().'/stats?'.self::PERIOD);

        self::assertResponseIsSuccessful();
        $figures = $crawler->filter('article h2')->each(static fn (Crawler $n): string => $n->text());
        self::assertSame(['5', '2', '0', '5'], $figures, 'all-time clicks, unique visitors, clicks today, clicks in the period');

        // one row per bucket of the seven-day period, with the running total
        $buckets = self::rows($crawler, 0);
        self::assertCount(7, $buckets);
        self::assertSame(['2026-09-02', '3', '1', '3'], $buckets[1]);
        self::assertSame(['2026-09-04', '2', '1', '5'], $buckets[3]);
        self::assertSame(['2026-09-03', '0', '0', '3'], $buckets[2], 'a day without clicks is a row of zeros, not a gap');

        $body = $crawler->filter('body')->text();
        foreach (['DE', 'FR', 'smartphone', 'desktop', 'iOS', 'Windows', 'news.example.com', 'direct'] as $expected) {
            self::assertStringContainsString($expected, $body, $expected.' is on the page');
        }
        self::assertSame(['A', '3', '1', '60.0%'], self::rows($crawler, 5)[0], 'the variants table');
    }

    public function testTheChartedSeriesIsAlsoATable(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        ClickRows::many(self::connection(), $link->getId(), 4, '2026-09-02T10:00:00Z', ['visitor' => 'v1']);

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/links/'.$link->getId().'/stats?'.self::PERIOD);

        // the chart is a canvas the browser fills in; the numbers do not depend on it
        self::assertCount(1, $crawler->filter('.lb-chart'));
        $buckets = self::rows($crawler, 0);
        self::assertCount(7, $buckets);
        self::assertSame('4', $buckets[1][1]);
    }

    public function testALinkNobodyHasClickedReadsAsZeros(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/links/'.$link->getId().'/stats?'.self::PERIOD);

        self::assertResponseIsSuccessful();
        self::assertSame(['0', '0', '0', '0'], $crawler->filter('article h2')->each(static fn (Crawler $n): string => $n->text()));
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('First click none yet', $body);
        self::assertStringContainsString('no countries to show', $body);
        self::assertStringContainsString('no devices to show', $body);
        self::assertStringContainsString('no operating systems to show', $body);
        self::assertStringContainsString('no referrers to show', $body);
        self::assertStringContainsString('nothing to compare', $body);
    }

    public function testClicksOutsideThePeriodKeepTheAllTimeFigures(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        ClickRows::many(self::connection(), $link->getId(), 6, '2026-08-10T10:00:00Z', ['visitor' => 'v1']);

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/links/'.$link->getId().'/stats?'.self::PERIOD);

        self::assertResponseIsSuccessful();
        $figures = $crawler->filter('article h2')->each(static fn (Crawler $n): string => $n->text());
        self::assertSame(['6', '1', '0'], \array_slice($figures, 0, 3), 'the all-time figures are not scoped to the period');
        self::assertSame('0', $figures[3], 'the period itself is empty');
        self::assertStringContainsString('2026-08-10', $crawler->filter('body')->text(), 'the first click keeps its date');
        self::assertStringContainsString('no countries to show', $crawler->filter('body')->text());
    }

    public function testTheLinkPageOffersTheStatisticsPage(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/links/'.$link->getId());

        self::assertCount(1, $crawler->filter('a[href="/links/'.$link->getId().'/stats"]'));
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @return list<list<string>>
     */
    private static function rows(Crawler $crawler, int $table): array
    {
        return $crawler->filter('table')->eq($table)->filter('tbody tr')->each(
            static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => trim($cell->text())),
        );
    }
}
