<?php

declare(strict_types=1);

namespace App\Tests\Api\Auth;

use App\Auth\RateLimit\ApiRateLimitHeaders;
use App\Auth\RateLimit\ApiRateLimitListener;
use App\Tests\Api\Link\LinkApiTestCase;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Spec api-keys "Per-identity API rate limit". The test kernel's api_identity
 * limiter runs on an in-process storage (one window per test client); the
 * exhaustion cases install a factory with a limit of 3 through the test
 * container so they need four requests, not six hundred.
 */
#[CoversClass(ApiRateLimitListener::class)]
#[CoversClass(ApiRateLimitHeaders::class)]
final class ApiRateLimitTest extends LinkApiTestCase
{
    public function testHeadersOnKeyAndJwtRequestsEachWithTheirOwnWindow(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne(['email' => 'a@example.com']);
        $key = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($key)->create(['owner' => $user]);

        $this->withKey($client, $key, 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('X-RateLimit-Limit', '600');
        self::assertResponseHeaderSame('X-RateLimit-Remaining', '599');

        $this->api($client, $this->token($client, 'a@example.com'), 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('X-RateLimit-Limit', '600');
        self::assertResponseHeaderSame('X-RateLimit-Remaining', '599', 'the JWT window is separate from the key window');
    }

    public function testExhaustionIs429AndTheOperationDoesNotRun(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne(['email' => 'a@example.com']);
        $keyA = ApiKeyFactory::plaintext();
        $keyB = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($keyA)->create(['owner' => $user]);
        ApiKeyFactory::new()->forPlaintext($keyB)->create(['owner' => $user]);
        self::getContainer()->set('limiter.api_identity', self::limiterWithLimit(3));

        for ($i = 3; $i >= 1; --$i) {
            $this->withKey($client, $keyA, 'GET', '/api/v1/me');
            self::assertResponseStatusCodeSame(200);
            self::assertResponseHeaderSame('X-RateLimit-Remaining', (string) ($i - 1));
        }
        $this->withKey($client, $keyA, 'POST', '/api/v1/links', ['targetUrl' => 'https://example.com/over-the-limit']);
        self::assertResponseStatusCodeSame(429);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertGreaterThanOrEqual(1, (int) $client->getResponse()->headers->get('Retry-After'));
        self::assertResponseHeaderSame('X-RateLimit-Limit', '3');
        self::assertResponseHeaderSame('X-RateLimit-Remaining', '0');
        self::assertSame(429, $this->decode($client)['status']);
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT count(*) FROM links WHERE target_url = 'https://example.com/over-the-limit'"), 'the operation did not run');

        $this->withKey($client, $keyB, 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200, 'a second key of the same user has its own window');
        $this->api($client, $this->token($client, 'a@example.com'), 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200, 'the JWT window is separate too');
    }

    public function testRoleDeniedRequestsAreCountedForBothCredentials(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne(['email' => 'a@example.com']);
        $key = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($key)->create(['owner' => $user]);
        self::getContainer()->set('limiter.api_identity', self::limiterWithLimit(2));
        $jwt = $this->token($client, 'a@example.com');

        foreach ([['key', 1], ['key', 0], ['jwt', 1], ['jwt', 0]] as [$credential, $remaining]) {
            'key' === $credential ? $this->withKey($client, $key, 'GET', '/api/v1/admin/stats/summary') : $this->api($client, $jwt, 'GET', '/api/v1/admin/stats/summary');
            self::assertResponseStatusCodeSame(403, "$credential: an ordinary user is refused by access control");
            self::assertResponseHeaderSame('X-RateLimit-Limit', '2', $credential);
            self::assertResponseHeaderSame('X-RateLimit-Remaining', (string) $remaining, "$credential: the refused request was counted");
        }
        $this->withKey($client, $key, 'GET', '/api/v1/admin/stats/summary');
        self::assertResponseStatusCodeSame(429, 'the key window is exhausted by refused requests');
        $this->api($client, $jwt, 'GET', '/api/v1/admin/stats/summary');
        self::assertResponseStatusCodeSame(429, 'so is the JWT window');
    }

    public function testAuthEndpointsAndAnonymousRequestsCarryNoHeaders(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'a@example.com', 'password' => UserFactory::PASSWORD]);
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Limit'), 'the auth endpoint keeps its per-IP limit only');

        $client->request('GET', '/api/v1/me', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Limit'), 'no identity, no limit');
    }

    public function testStoreUnavailableFailsOpenWithAWarning(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne(['email' => 'a@example.com']);
        $key = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($key)->create(['owner' => $user]);
        $storage = new class implements StorageInterface {
            public function save(\Symfony\Component\RateLimiter\LimiterStateInterface $limiterState): void
            {
                throw new \RuntimeException('storage down');
            }

            public function fetch(string $limiterStateId): ?\Symfony\Component\RateLimiter\LimiterStateInterface
            {
                throw new \RuntimeException('storage down');
            }

            public function delete(string $limiterStateId): void
            {
            }
        };
        self::getContainer()->set('limiter.api_identity', new RateLimiterFactory(['id' => 'api_identity', 'policy' => 'sliding_window', 'limit' => 600, 'interval' => '1 minute'], $storage));
        $log = new TestHandler();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($log);

        $this->withKey($client, $key, 'GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($client->getResponse()->headers->has('X-RateLimit-Limit'));
        self::assertTrue($log->hasWarningThatContains('API rate limiter unavailable'));
        $record = $log->getRecords()[array_key_last($log->getRecords())];
        self::assertSame(['exception' => \RuntimeException::class], $record->context);
    }

    private static function limiterWithLimit(int $limit): RateLimiterFactoryInterface
    {
        return new RateLimiterFactory(['id' => 'api_identity', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage());
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function withKey(KernelBrowser $client, string $key, string $method, string $uri, ?array $body = null): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
