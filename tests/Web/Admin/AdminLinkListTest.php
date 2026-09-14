<?php

declare(strict_types=1);

namespace App\Tests\Web\Admin;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\StatementRecorder;
use App\Tests\Web\WebPageTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DomCrawler\Crawler;

/** Spec web-ui — "The administrative links page lists every user's links". */
#[CoversNothing]
final class AdminLinkListTest extends WebPageTestCase
{
    public function testLinksOfEveryOwnerAreListedWithTheirOwner(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        LinkFactory::createOne(['owner' => $bea, 'slug' => 'bea-one']);

        $this->signIn($client, 'root@example.com');
        $crawler = $client->request('GET', '/admin/links');

        $rows = self::rows($crawler);
        self::assertCount(2, $rows);
        $bySlug = array_column($rows, 1, 0);
        self::assertSame('ann@example.com', $bySlug['ann-one']);
        self::assertSame('bea@example.com', $bySlug['bea-one']);
    }

    public function testTheOwnersOwnListStillShowsOnlyTheirOwn(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        LinkFactory::createOne(['owner' => $bea, 'slug' => 'bea-one']);

        $this->signIn($client, 'ann@example.com');
        $body = $client->request('GET', '/links')->filter('body')->text();

        self::assertStringContainsString('ann-one', $body);
        self::assertStringNotContainsString('bea-one', $body);
    }

    public function testTheFiltersAndOrderingSelectExactlyTheExpectedLinks(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        LinkFactory::new()->limited(100, 5)->create(['owner' => $ann, 'slug' => 'promo-one']);
        LinkFactory::new()->limited(100, 9)->create(['owner' => $bea, 'slug' => 'promo-two']);
        LinkFactory::new()->inactive()->create(['owner' => $bea, 'slug' => 'promo-off']);
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'other']);

        $this->signIn($client, 'root@example.com');

        $matching = self::rows($client->request('GET', '/admin/links?slug=promo'));
        self::assertSame(['promo-off', 'promo-two', 'promo-one'], array_column($matching, 0), 'newest first by default');

        $active = self::rows($client->request('GET', '/admin/links?slug=promo&state=active&order=clickCount'));
        self::assertSame(['promo-two', 'promo-one'], array_column($active, 0), 'most clicked first, the inactive one gone');

        $ascending = self::rows($client->request('GET', '/admin/links?slug=promo&state=active&order=clickCount&direction=asc'));
        self::assertSame(['promo-one', 'promo-two'], array_column($ascending, 0));

        $inactive = self::rows($client->request('GET', '/admin/links?state=inactive'));
        self::assertSame(['promo-off'], array_column($inactive, 0));
    }

    public function testOwnersAreLookedUpOnceForThePageNotOncePerRow(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $owners = UserFactory::createMany(5);
        foreach ($owners as $owner) {
            LinkFactory::createMany(4, ['owner' => $owner]);
        }

        $this->signIn($client, 'root@example.com');
        // the fixtures were made through this very entity manager, so without
        // clearing it the identity map would answer every owner lookup and the
        // assertion below would hold however the page fetches them
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        StatementRecorder::reset();
        $crawler = $client->request('GET', '/admin/links');

        self::assertCount(20, $crawler->filter('tbody tr'));
        $userQueries = array_values(array_filter(
            StatementRecorder::statements(),
            static fn (string $sql): bool => (bool) preg_match('/\bFROM\s+users\b/i', $sql),
        ));
        self::assertLessThanOrEqual(2, \count($userQueries), 'one lookup for the page plus the signed-in user, never one per row: '.\count($userQueries));
    }

    /**
     * @return list<list<string>>
     */
    private static function rows(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr')->each(
            static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => trim($cell->text())),
        );
    }
}
