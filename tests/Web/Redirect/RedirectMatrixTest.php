<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec redirect, requirements "Public redirect endpoint", "Response matrix",
 * "Destination with UTM appended", "Headers and bodies" over HTTP.
 */
#[CoversNothing]
final class RedirectMatrixTest extends RedirectWebTestCase
{
    public function testActiveLinkRedirectsWithUtmHeadersAndNoCookie(): void
    {
        $client = self::createClient();
        LinkFactory::new()->withUtm(['utm_source' => 'newsletter', 'utm_campaign' => 'spring sale'])->create(['slug' => 'promo-1', 'targetUrl' => 'https://example.com/p?a=1&utm_source=old#top']);

        self::visit($client, '/promo-1');

        self::assertResponseStatusCodeSame(302);
        self::assertNotSame(301, $client->getResponse()->getStatusCode());
        self::assertResponseHeaderSame('Location', 'https://example.com/p?a=1&utm_source=newsletter&utm_campaign=spring%20sale#top');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer-when-downgrade');
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
        self::assertSame([], $client->getResponse()->headers->getCookies());
    }

    public function testLinkWithoutUtmRedirectsToTheExactTarget(): void
    {
        $client = self::createClient();
        LinkFactory::createOne(['slug' => 'plain', 'targetUrl' => 'https://example.com/p?a=1']);

        self::visit($client, '/plain');

        self::assertResponseHeaderSame('Location', 'https://example.com/p?a=1');
    }

    public function testApiPrefixedSlugIsPublicWhileTheApiStaysProtected(): void
    {
        $client = self::createClient();
        LinkFactory::createOne(['slug' => 'api-promo', 'targetUrl' => 'https://example.com/api-promo']);

        self::visit($client, '/api-promo');
        self::assertResponseStatusCodeSame(302);

        self::visit($client, '/api-promo', ['HTTP_AUTHORIZATION' => 'Bearer not-a-token']);
        self::assertResponseStatusCodeSame(302, 'an invalid bearer must not turn a redirect into 401 (firewall boundary)');

        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-token', 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testSlugsAreCaseSensitive(): void
    {
        $client = self::createClient();
        LinkFactory::createOne(['slug' => 'Promo']);

        self::visit($client, '/promo');
        self::assertResponseStatusCodeSame(404);

        self::visit($client, '/Promo');
        self::assertResponseStatusCodeSame(302);
    }

    public function testUnknownInactiveExpiredAndExhaustedLinks(): void
    {
        $client = self::createClient();
        LinkFactory::new()->inactive()->create(['slug' => 'inactive']);
        LinkFactory::new()->inactive()->expiring(new \DateTimeImmutable('-1 day'))->create(['slug' => 'inactive-expired']);
        LinkFactory::new()->expiring(new \DateTimeImmutable('-1 minute'))->create(['slug' => 'expired']);
        LinkFactory::new()->limited(3, 3)->create(['slug' => 'exhausted']);

        self::visit($client, '/nothing-here');
        self::assertResponseStatusCodeSame(404);
        self::visit($client, '/inactive');
        self::assertResponseStatusCodeSame(404);
        self::visit($client, '/inactive-expired');
        self::assertResponseStatusCodeSame(404, 'inactivity is checked before expiry');
        self::visit($client, '/expired');
        self::assertResponseStatusCodeSame(410);
        self::visit($client, '/exhausted');
        self::assertResponseStatusCodeSame(410);
    }

    public function testLastAllowedClickRedirectsAndCountsThenTheLinkIsGone(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::new()->limited(3, 2)->create(['owner' => $owner, 'slug' => 'almost', 'targetUrl' => 'https://example.com/a']);

        self::visit($client, '/almost');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/a');

        self::consumeAsync(); // the count is written by the worker
        $token = $this->token($client, 'a@example.com');
        self::assertSame(3, $this->apiGet($client, $token, '/api/v1/links/'.$link->getId())['clickCount']);

        self::visit($client, '/almost');
        self::assertResponseStatusCodeSame(410);
    }

    public function testErrorHeadersAndBodiesByAccept(): void
    {
        $client = self::createClient();
        LinkFactory::new()->expiring(new \DateTimeImmutable('-1 minute'))->create(['slug' => 'expired']);

        self::visit($client, '/nothing-here', ['HTTP_ACCEPT' => 'text/html']);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('[role=alert]');

        self::visit($client, '/expired', ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(410);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(410, $problem['status']);
        self::assertSame('Gone', $problem['title']);
        self::assertArrayHasKey('detail', $problem);

        self::visit($client, '/expired', ['HTTP_ACCEPT' => 'text/html']);
        self::assertResponseStatusCodeSame(410);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
    }

    public function testHeadMirrorsGetWithoutRecording(): void
    {
        $client = self::createClient();
        $link = LinkFactory::new()->withUtm(['utm_source' => 'x'])->create(['slug' => 'headed', 'targetUrl' => 'https://example.com/h']);
        LinkFactory::new()->expiring(new \DateTimeImmutable('-1 minute'))->create(['slug' => 'expired']);

        self::visit($client, '/headed', method: 'HEAD');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/h?utm_source=x');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer-when-downgrade');
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertCount(0, self::clicksOf($link->getId()));
        self::assertSame(0, self::clickCountOf($link->getId()));

        self::visit($client, '/expired', method: 'HEAD');
        self::assertResponseStatusCodeSame(410);
        self::visit($client, '/nothing-here', method: 'HEAD');
        self::assertResponseStatusCodeSame(404);
    }
}
