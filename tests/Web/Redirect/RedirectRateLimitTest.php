<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Tests\Factory\LinkFactory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec redirect, "Per-IP rate limit". The test kernel uses the in-memory
 * storage (RATE_LIMIT_REDIRECT_PER_IP = 60), so each test starts with an
 * empty window; disableReboot keeps one window for the whole test.
 */
#[CoversNothing]
final class RedirectRateLimitTest extends RedirectWebTestCase
{
    public function testSixtyFirstRequestFromOneAddressIs429(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'hot']);

        for ($i = 1; $i <= 60; ++$i) {
            self::visit($client, '/hot');
            self::assertResponseStatusCodeSame(302, "request $i is still served by the matrix");
        }

        self::visit($client, '/hot');
        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThanOrEqual(1, (int) $client->getResponse()->headers->get('Retry-After'));
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextContains('[role=alert]', 'seconds');
        self::assertCount(60, self::clicksOf($link->getId()), 'the refused request touched neither the link nor the clicks');

        self::visit($client, '/hot', ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(429);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(429, $problem['status']);
    }

    public function testSpoofedForwardedForFromAnUntrustedPeerSharesOneBucket(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        LinkFactory::createOne(['slug' => 'hot']);

        // 198.51.100.7 is not in TRUSTED_PROXIES, so the forwarded header is ignored
        for ($i = 1; $i <= 60; ++$i) {
            self::visit($client, '/hot', ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => "203.0.113.$i"]);
            self::assertResponseStatusCodeSame(302);
        }
        self::visit($client, '/hot', ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.99']);

        self::assertResponseStatusCodeSame(429);
    }
}
