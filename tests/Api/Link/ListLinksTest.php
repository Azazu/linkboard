<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec links: "Read and list own links".
 */
#[CoversNothing]
final class ListLinksTest extends LinkApiTestCase
{
    public function testOnlyTheCallersLinksNewestFirst(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $b = UserFactory::createOne(['email' => 'b@example.com']);
        LinkFactory::createOne(['owner' => $a, 'slug' => 'first', 'now' => new \DateTimeImmutable('2026-01-01T00:00:00Z')]);
        LinkFactory::createOne(['owner' => $a, 'slug' => 'second', 'now' => new \DateTimeImmutable('2026-01-02T00:00:00Z')]);
        LinkFactory::createOne(['owner' => $b, 'slug' => 'theirs']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'GET', '/api/v1/links');

        self::assertResponseStatusCodeSame(200);
        $page = $this->decode($client);
        self::assertSame(2, $page['totalItems']);
        self::assertSame(1, $page['page']);
        self::assertSame(30, $page['itemsPerPage']);
        self::assertSame(['second', 'first'], array_column($page['items'], 'slug'));
        self::assertSame([(string) $a->getId()], array_values(array_unique(array_column($page['items'], 'ownerId'))));
    }

    public function testFiltersOrderAndPageSizeCap(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        LinkFactory::createOne(['owner' => $a, 'slug' => 'promo-1', 'now' => new \DateTimeImmutable('2026-01-01T00:00:00Z')]);
        LinkFactory::new()->inactive()->create(['owner' => $a, 'slug' => 'promo-2', 'now' => new \DateTimeImmutable('2026-01-02T00:00:00Z')]);
        LinkFactory::createOne(['owner' => $a, 'slug' => 'other', 'now' => new \DateTimeImmutable('2026-01-03T00:00:00Z')]);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'GET', '/api/v1/links?isActive=false&slug=promo');
        self::assertSame(['promo-2'], array_column($this->decode($client)['items'], 'slug'));

        $this->api($client, $token, 'GET', '/api/v1/links?order[createdAt]=asc');
        self::assertSame(['promo-1', 'promo-2', 'other'], array_column($this->decode($client)['items'], 'slug'));

        $this->api($client, $token, 'GET', '/api/v1/links?itemsPerPage=500');
        self::assertSame(100, $this->decode($client)['itemsPerPage']);

        $this->api($client, $token, 'GET', '/api/v1/links?isActive=maybe');
        self::assertResponseStatusCodeSame(400);
        $this->api($client, $token, 'GET', '/api/v1/links?order[slug]=asc');
        self::assertResponseStatusCodeSame(400);
    }

    public function testItemReadCarriesShortUrlAndClickCount(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a, 'slug' => 'read-me']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'GET', '/api/v1/links/'.$link->getId());

        self::assertResponseStatusCodeSame(200);
        $body = $this->decode($client);
        self::assertSame('http://localhost:8082/read-me', $body['shortUrl']);
        self::assertSame(0, $body['clickCount']);

        $this->api($client, $token, 'GET', '/api/v1/links/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
        $this->api($client, $token, 'GET', '/api/v1/links/00000000-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }
}
