<?php

declare(strict_types=1);

namespace App\Tests\Web\Stats;

use App\Tests\Factory\LinkFactory;
use App\Tests\Fixture\ClickRows;
use App\Tests\Web\WebPageTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * Spec web-ui — "The statistics page reports a refused parameter on its own
 * control". The rules belong to the `analytics` capability; what is asserted
 * here is that the page keeps the reader, names the control and shows no
 * figures computed from a value that was refused.
 */
#[CoversNothing]
final class LinkStatsParametersTest extends WebPageTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'the end precedes the start' => ['from=2026-09-08&to=2026-09-01', 'from'];
        yield 'a period beyond the maximum' => ['from=2024-01-01&to=2026-01-01', 'to'];
        yield 'hourly buckets over too long a period' => ['from=2026-06-01&to=2026-09-01&granularity=hour', 'granularity'];
        yield 'a bound that is not a date' => ['from=yesterday', 'from'];
    }

    #[DataProvider('refusals')]
    public function testARefusedParameterKeepsTheReaderOnThePage(string $query, string $control): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        ClickRows::many(self::connection(), $link->getId(), 3, '2026-09-02T10:00:00Z');

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/links/'.$link->getId().'/stats?'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('/links/'.$link->getId().'/stats', parse_url((string) $client->getRequest()->getUri(), \PHP_URL_PATH));
        self::assertCount(0, $crawler->filter('table'), 'no figures are computed from a refused parameter');
        self::assertGreaterThan(0, $crawler->filter('[name="'.$control.'"][aria-invalid="true"]')->count(), $control.' carries the refusal');
        self::assertGreaterThan(0, $crawler->filter('[role=alert]')->count());
    }

    public function testTheChosenParametersTravelInTheAddress(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        ClickRows::many(self::connection(), $link->getId(), 2, '2026-09-02T10:00:00Z');
        $address = '/links/'.$link->getId().'/stats?from=2026-09-01&to=2026-09-08&granularity=hour&includeBots=1';

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', $address);

        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-01', $crawler->filter('input[name=from]')->attr('value'));
        self::assertSame('2026-09-08', $crawler->filter('input[name=to]')->attr('value'));
        self::assertSame('hour', $crawler->filter('select[name=granularity] option[selected]')->attr('value'));
        self::assertNotNull($crawler->filter('input[name=includeBots]')->attr('checked'));
        self::assertCount(7 * 24, self::bucketRows($crawler), 'hourly buckets over seven days');
    }

    public function testTheBotsToggleChangesTheFigures(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        ClickRows::many(self::connection(), $link->getId(), 3, '2026-09-02T10:00:00Z', ['visitor' => 'human']);
        ClickRows::many(self::connection(), $link->getId(), 2, '2026-09-02T11:00:00Z', ['bot' => true, 'visitor' => 'robot']);
        $base = '/links/'.$link->getId().'/stats?from=2026-09-01&to=2026-09-08';

        $this->signIn($client, 'ann@example.com');
        $without = $client->request('GET', $base);
        self::assertStringContainsString('bots excluded', $without->filter('body')->text());
        self::assertSame('3', self::figures($without)[3]);

        $with = $client->request('GET', $base.'&includeBots=1');
        self::assertStringContainsString('bots included', $with->filter('body')->text());
        self::assertSame('5', self::figures($with)[3]);
    }

    public function testAnUnknownLinkIsNotDisclosedByTheParameterCheck(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        // the link is looked up and the permission asked first: a refused
        // parameter must not turn a 404 into a 422 that admits the id exists
        $client->request('GET', '/links/'.Uuid::v7()->toRfc4122().'/stats?from=2026-09-08&to=2026-09-01');

        self::assertResponseStatusCodeSame(404);
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @return list<string>
     */
    private static function figures(Crawler $crawler): array
    {
        return $crawler->filter('article h2')->each(static fn (Crawler $n): string => $n->text());
    }

    /**
     * @return list<string>
     */
    private static function bucketRows(Crawler $crawler): array
    {
        return $crawler->filter('table')->eq(0)->filter('tbody tr')->each(static fn (Crawler $row): string => $row->text());
    }
}
