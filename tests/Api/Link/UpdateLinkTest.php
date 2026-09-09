<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Link\LinkRepositoryInterface;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Uid\Uuid;

/**
 * Spec links: "Update a link" — merge-patch presence and the null contract.
 */
#[CoversNothing]
final class UpdateLinkTest extends LinkApiTestCase
{
    public function testAbsentFieldsArePreservedAndExplicitNullClearsOneField(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner]);
        $now = new \DateTimeImmutable();
        $link->setClickLimit(100, $now);
        $link->setExpiry($now->modify('+1 day'), $now);
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();
        $token = $this->token($client, 'a@example.com');
        $id = (string) $link->getId();

        $this->api($client, $token, 'PATCH', "/api/v1/links/$id", ['isActive' => false]);
        self::assertResponseStatusCodeSame(200);
        $body = $this->decode($client);
        self::assertFalse($body['isActive']);
        self::assertSame(100, $body['maxClicks']);
        self::assertNotNull($body['expiresAt']);

        $this->api($client, $token, 'PATCH', "/api/v1/links/$id", ['maxClicks' => null]);
        self::assertResponseStatusCodeSame(200);
        $body = $this->decode($client);
        self::assertNull($body['maxClicks']);
        self::assertNotNull($body['expiresAt']);
        self::assertFalse($body['isActive']);
    }

    public function testTargetChangeAndReactivation(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::new()->inactive()->create(['owner' => $owner]);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['isActive' => true, 'targetUrl' => 'https://example.org/new', 'utm' => ['utm_medium' => 'email']]);

        self::assertResponseStatusCodeSame(200);
        $body = $this->decode($client);
        self::assertTrue($body['isActive']);
        self::assertSame('https://example.org/new', $body['targetUrl']);
        self::assertSame(['utm_medium' => 'email'], $body['utm']);
        // timestamps have second precision: an update within the creation second is equal, never earlier
        self::assertGreaterThanOrEqual(new \DateTimeImmutable($body['createdAt']), new \DateTimeImmutable($body['updatedAt']));
    }

    public function testNullWhereAValueIsRequiredIs422(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner]);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['targetUrl' => null]);
        self::assertSame(['targetUrl'], $this->violationPaths($client));

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['isActive' => null]);
        self::assertSame(['isActive'], $this->violationPaths($client));
    }

    public function testSlugIsImmutable(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'keep-me']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['slug' => 'other']);
        self::assertSame(['slug'], $this->violationPaths($client));

        // the same slug is not a change
        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['slug' => 'keep-me', 'isActive' => false]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('keep-me', self::getContainer()->get(LinkRepositoryInterface::class)->findById(Uuid::fromString((string) $link->getId()))?->getSlug());
    }

    public function testInvalidTargetLeavesTheLinkUnchanged(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'targetUrl' => 'https://example.com/original']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['targetUrl' => 'http://10.0.0.1/']);

        self::assertSame(['targetUrl'], $this->violationPaths($client));
        $this->api($client, $token, 'GET', '/api/v1/links/'.$link->getId());
        self::assertSame('https://example.com/original', $this->decode($client)['targetUrl']);
    }

    public function testNonObjectBodyIs400(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner]);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), rawBody: '[1,2]');

        self::assertResponseStatusCodeSame(400);
    }
}
