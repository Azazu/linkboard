<?php

declare(strict_types=1);

namespace App\Tests\Web\Link;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\Json;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec web-ui — "A link page belongs to its owner": the pages answer 404 for a
 * link the signed-in user may not see, identical to the answer for an
 * identifier no link has, while the API keeps the 403 its own capability
 * requires.
 */
#[CoversNothing]
final class LinkShowTest extends WebPageTestCase
{
    public function testTheOwnerSeesTheLinkItsShortUrlAndItsQrCode(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one', 'targetUrl' => 'https://example.com/x']);
        $this->signIn($client, 'ann@example.com');

        $crawler = $client->request('GET', '/links/'.$link->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'ann-one');
        self::assertStringContainsString('/ann-one', $crawler->filter('[data-clipboard-target=source]')->text());
        self::assertCount(1, $crawler->filter('img[src$="/qr"]'));
    }

    public function testTheQrCodeIsServedInBothFormats(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$link->getId().'/qr');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        // Symfony normalises the directives; the two that matter are these
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('max-age=86400', $cacheControl);

        $client->request('GET', '/links/'.$link->getId().'/qr?format=png');
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testAStrangerCannotTellAnExistingLinkFromAnUnknownOne(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $this->user('bea@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'bea@example.com');

        $bodies = [];
        foreach ([(string) $link->getId(), '018f0000-0000-7000-8000-000000000099'] as $id) {
            foreach (['', '/edit', '/qr'] as $suffix) {
                $client->request('GET', '/links/'.$id.$suffix);
                self::assertResponseStatusCodeSame(404, $id.$suffix);
                $bodies[] = (string) $client->getResponse()->getContent();
            }
        }

        self::assertCount(1, array_unique($bodies), 'an existing link and an unknown identifier look the same');
    }

    public function testTheApiStillAnswersAStrangerWithForbidden(): void
    {
        // the pages' 404 is a rendering decision; the API's contract (spec links)
        // is unchanged and this is what pins it
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $this->user('bea@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'bea@example.com', 'password' => UserFactory::PASSWORD]);
        $token = Json::string(Json::decode($client->getResponse()->getContent()), 'token');

        $client->request('GET', '/api/v1/links/'.$link->getId(), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdministratorReachesAnotherUsersLink(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $this->user('root@example.com', admin: true);
        $link = LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        $this->signIn($client, 'root@example.com');

        $client->request('GET', '/links/'.$link->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'ann-one');
    }

    public function testAMalformedIdentifierIsAlsoFourOhFour(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/not-a-uuid-at-all-not-even-close');

        self::assertResponseStatusCodeSame(404);
    }
}
