<?php

declare(strict_types=1);

namespace App\Tests\Api\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec: authentication — "Auth endpoints are rate limited per client IP".
 * The test kernel uses the array storage, so each test starts with an
 * empty window (RATE_LIMIT_AUTH_PER_IP = 10 in every environment).
 */
#[CoversNothing]
final class RateLimitTest extends WebTestCase
{
    public function testEleventhAttemptWithinAMinuteIs429WithRetryAfter(): void
    {
        $client = self::createClient();
        $client->disableReboot(); // one kernel, one limiter window for the whole test

        for ($i = 1; $i <= 10; ++$i) {
            $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'x@example.com', 'password' => 'wrong-password-here']);
            self::assertResponseStatusCodeSame(401, "attempt $i must still be evaluated");
        }

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'x@example.com', 'password' => 'wrong-password-here']);

        self::assertResponseStatusCodeSame(429);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertGreaterThanOrEqual(1, (int) $client->getResponse()->headers->get('Retry-After'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(429, $problem['status']);
    }

    public function testSpoofedForwardedForFromAnUntrustedPeerIsIgnored(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        // Ten attempts, each claiming a different forwarded address. The test
        // client's peer (127.0.0.1) is a trusted proxy in .env, so the header
        // WOULD be honored — unless the peer is not trusted. Make it untrusted.
        for ($i = 1; $i <= 10; ++$i) {
            $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'x@example.com', 'password' => 'wrong-password-here'], [
                'REMOTE_ADDR' => '198.51.100.7',
                'HTTP_X_FORWARDED_FOR' => "203.0.113.$i",
            ]);
            self::assertResponseStatusCodeSame(401);
        }

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'x@example.com', 'password' => 'wrong-password-here'], [
            'REMOTE_ADDR' => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        ]);

        // counted against 198.51.100.7, not the forged addresses
        self::assertResponseStatusCodeSame(429);
    }

    public function testWebRegisterOverTheLimitRendersAnHtmlPage(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        for ($i = 1; $i <= 10; ++$i) {
            $client->request('POST', '/register');
        }
        $client->request('POST', '/register');

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertSelectorTextContains('[role=alert]', 'seconds');
    }
}
