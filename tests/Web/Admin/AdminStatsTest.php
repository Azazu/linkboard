<?php

declare(strict_types=1);

namespace App\Tests\Web\Admin;

use App\Analytics\Report\GlobalReports;
use App\Analytics\Report\Period;
use App\Analytics\Report\ReportRequest;
use App\Tests\Factory\LinkFactory;
use App\Tests\Fixture\ClickRows;
use App\Tests\Web\WebPageTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DomCrawler\Crawler;

/** Spec web-ui — "The global statistics page shows the service-wide reports". */
#[CoversNothing]
final class AdminStatsTest extends WebPageTestCase
{
    private const string PERIOD = 'from=2026-09-01&to=2026-09-08';

    protected function setUp(): void
    {
        parent::setUp();
        // the pool is namespaced for the test environment but outlives a run:
        // a global report's key is the same in every test, so an entry left by
        // an earlier one would answer here
        self::clearReportCache();
    }

    protected function tearDown(): void
    {
        self::clearReportCache();
        parent::tearDown();
    }

    private static function clearReportCache(): void
    {
        $booted = null !== self::$kernel;
        if (!$booted) {
            self::bootKernel();
        }
        $pool = self::getContainer()->get('cache.reports');
        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }
        if (!$booted) {
            self::ensureKernelShutdown();
        }
    }

    public function testTheThreeGlobalReportsAreOnThePage(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        $anns = LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        $beas = LinkFactory::createOne(['owner' => $bea, 'slug' => 'bea-one']);
        LinkFactory::new()->inactive()->create(['owner' => $bea, 'slug' => 'bea-off']);
        $connection = self::connection();
        ClickRows::many($connection, $anns->getId(), 5, '2026-09-02T10:00:00Z', ['visitor' => 'v1']);
        ClickRows::many($connection, $beas->getId(), 3, '2026-09-04T10:00:00Z', ['visitor' => 'v2']);

        $this->signIn($client, 'root@example.com');
        $crawler = $client->request('GET', '/admin/stats?'.self::PERIOD);

        self::assertResponseIsSuccessful();
        $totals = $crawler->filter('article h2')->each(static fn (Crawler $n): string => $n->text());
        self::assertSame(['3', '3', '8'], \array_slice($totals, 0, 3), 'accounts, links, clicks all time');
        self::assertStringContainsString('2 active', $crawler->filter('article')->eq(1)->text());

        $buckets = self::rows($crawler, 0);
        self::assertCount(7, $buckets, 'one row per day of the period');
        self::assertSame(['2026-09-02', '5', '5'], $buckets[1]);
        self::assertSame(['2026-09-04', '3', '8'], $buckets[3], 'the running total carries across the gap');
        self::assertSame(['2026-09-03', '0', '5'], $buckets[2]);

        $top = self::rows($crawler, 1);
        self::assertSame([['1', 'ann-one', (string) $ann->getId(), '5', '1'], ['2', 'bea-one', (string) $bea->getId(), '3', '1']], $top);
    }

    public function testThePageShowsTheReportTheCapabilityAnswers(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        ClickRows::many(self::connection(), $link->getId(), 4, '2026-09-02T10:00:00Z');

        $this->signIn($client, 'root@example.com');
        $page = $client->request('GET', '/admin/stats?'.self::PERIOD);

        // the very report the API's provider would answer for these parameters;
        // that both reach the same cache entry is CacheSharedWithPagesTest
        $reports = self::getContainer()->get(GlobalReports::class);
        self::assertInstanceOf(GlobalReports::class, $reports);
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $report = $reports->timeseries(new ReportRequest(null, Period::of('2026-09-01T00:00:00Z', '2026-09-08T00:00:00Z', $clock)));

        $rows = self::rows($page, 0);
        self::assertCount(\count($report->buckets), $rows);
        foreach ($report->buckets as $i => $bucket) {
            self::assertSame([$bucket->bucket->format('Y-m-d'), (string) $bucket->clicks, (string) $bucket->cumulativeClicks], $rows[$i]);
        }
    }

    public function testARefusedPeriodIsShownOnItsControl(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);

        $this->signIn($client, 'root@example.com');
        $crawler = $client->request('GET', '/admin/stats?from=2026-09-08&to=2026-09-01');

        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(0, $crawler->filter('[name=from][aria-invalid="true"]')->count());
        self::assertCount(0, $crawler->filter('table'), 'no figures are computed from a refused parameter');
    }

    public function testTheTopNControlCarriesItsValueAndItsRefusal(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $ann = $this->user('ann@example.com');
        foreach (range(1, 12) as $i) {
            $link = LinkFactory::createOne(['owner' => $ann, 'slug' => 'top-'.$i]);
            ClickRows::many(self::connection(), $link->getId(), 13 - $i, '2026-09-02T10:00:00Z');
        }

        $this->signIn($client, 'root@example.com');
        $ten = $client->request('GET', '/admin/stats?'.self::PERIOD);
        self::assertCount(10, self::rows($ten, 1), 'the default top-N');

        $more = $client->request('GET', '/admin/stats?'.self::PERIOD.'&limit=25');
        self::assertSame('25', $more->filter('select[name=limit] option[selected]')->attr('value'));
        self::assertCount(12, self::rows($more, 1), 'every link that was clicked');

        $refused = $client->request('GET', '/admin/stats?'.self::PERIOD.'&limit=500');
        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(0, $refused->filter('select[name=limit][aria-invalid="true"]')->count());
    }

    public function testThePageIsReachableFromTheOtherAdministrativePages(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);

        $this->signIn($client, 'root@example.com');
        $crawler = $client->request('GET', '/admin/users');

        self::assertCount(1, $crawler->filter('main a[href="/admin/stats"]'));
        self::assertCount(1, $crawler->filter('main a[href="/admin/links"]'));
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
