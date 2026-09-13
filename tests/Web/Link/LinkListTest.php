<?php

declare(strict_types=1);

namespace App\Tests\Web\Link;

use App\Tests\Factory\LinkFactory;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Spec web-ui — the links list: the owner's rows only, filtered, ordered, paginated. */
#[CoversNothing]
final class LinkListTest extends WebPageTestCase
{
    public function testOnlyTheOwnersLinksAreListed(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        LinkFactory::createOne(['owner' => $bea, 'slug' => 'bea-one']);

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/links');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ann-one', $crawler->filter('table')->text());
        self::assertStringNotContainsString('bea-one', $crawler->filter('body')->text());
    }

    public function testFiltersAndOrderingChangeTheRows(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'keep-active']);
        LinkFactory::new(['owner' => $ann, 'slug' => 'gone-quiet'])->inactive()->create();
        LinkFactory::new(['owner' => $ann, 'slug' => 'most-clicked'])->limited(1000, 500)->create();

        $this->signIn($client, 'ann@example.com');

        $active = $client->request('GET', '/links?state=active')->filter('table')->text();
        self::assertStringContainsString('keep-active', $active);
        self::assertStringNotContainsString('gone-quiet', $active);

        $inactive = $client->request('GET', '/links?state=inactive')->filter('table')->text();
        self::assertStringContainsString('gone-quiet', $inactive);
        self::assertStringNotContainsString('keep-active', $inactive);

        $matching = $client->request('GET', '/links?slug=quiet')->filter('table')->text();
        self::assertStringContainsString('gone-quiet', $matching);
        self::assertStringNotContainsString('keep-active', $matching);

        $byClicks = $client->request('GET', '/links?order=clickCount&direction=desc')->filter('tbody tr td:first-child')->each(static fn ($node): string => $node->text());
        self::assertSame('most-clicked', $byClicks[0]);
    }

    public function testTheSecondPageHoldsTheRest(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        LinkFactory::createMany(31, ['owner' => $ann]);

        $this->signIn($client, 'ann@example.com');

        $first = $client->request('GET', '/links');
        self::assertCount(30, $first->filter('tbody tr'));
        self::assertSelectorTextContains('body', 'Page 1 of 2');

        $second = $client->request('GET', '/links?page=2');
        self::assertCount(1, $second->filter('tbody tr'));
    }

    public function testAnOutOfRangePageIsClampedRatherThanAnError(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'only-one']);

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/links?page=99&order=nonsense&state=sideways');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('only-one', $crawler->filter('table')->text());
    }
}
