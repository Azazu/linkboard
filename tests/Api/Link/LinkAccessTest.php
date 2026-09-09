<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec links: "Ownership and admin access" — the LinkVoter matrix.
 */
#[CoversNothing]
final class LinkAccessTest extends LinkApiTestCase
{
    public function testStrangerAdminAndAnonymousOnEveryItemOperation(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        UserFactory::createOne(['email' => 'b@example.com']);
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $uri = '/api/v1/links/'.$link->getId();

        $b = $this->token($client, 'b@example.com');
        foreach ([['GET', null], ['PATCH', ['isActive' => false]], ['DELETE', null]] as [$method, $body]) {
            $this->api($client, $b, $method, $uri, $body);
            self::assertResponseStatusCodeSame(403, "$method as a stranger");
            self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        }

        foreach ([['GET', null, 200], ['PATCH', ['isActive' => false], 200], ['DELETE', null, 204]] as [$method, $body, $expected]) {
            $client->request($method, $uri, server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/merge-patch+json'], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(401, "$method anonymous");
        }

        $admin = $this->token($client, 'admin@example.com');
        foreach ([['GET', null, 200], ['PATCH', ['isActive' => false], 200], ['DELETE', null, 204]] as [$method, $body, $expected]) {
            $this->api($client, $admin, $method, $uri, $body);
            self::assertResponseStatusCodeSame($expected, "$method as admin");
        }
    }

    public function testAdminListingShowsEveryOwner(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $b = UserFactory::createOne(['email' => 'b@example.com']);
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        LinkFactory::createOne(['owner' => $a]);
        LinkFactory::createOne(['owner' => $b]);

        $this->api($client, $this->token($client, 'admin@example.com'), 'GET', '/api/v1/admin/links');
        self::assertResponseStatusCodeSame(200);
        $page = $this->decode($client);
        self::assertSame(2, $page['totalItems']);
        self::assertEqualsCanonicalizing([(string) $a->getId(), (string) $b->getId()], array_column($page['items'], 'ownerId'));

        $this->api($client, $this->token($client, 'a@example.com'), 'GET', '/api/v1/admin/links');
        self::assertResponseStatusCodeSame(403);
    }
}
