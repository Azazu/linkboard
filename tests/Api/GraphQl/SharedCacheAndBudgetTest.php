<?php

declare(strict_types=1);

namespace App\Tests\Api\GraphQl;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\StatementRecorder;
use App\Tests\Support\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Spec graphql-api "Report parameters are the same parameters" — the half that
 * says two protocols share one cached report — and "A GraphQL document costs
 * what the work costs".
 */
#[CoversNothing]
final class SharedCacheAndBudgetTest extends GraphQlTestCase
{
    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $pool = self::getContainer()->get('cache.reports');
            if ($pool instanceof CacheItemPoolInterface) {
                $pool->clear();
            }
        }
        parent::tearDown();
    }

    public function testARestCallAndAGraphQlQueryShareOneCachedReport(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner]);
        $token = $this->token($client, 'a@example.com');
        $period = 'from=2026-09-01T00:00:00Z&to=2026-09-08T00:00:00Z';

        $client->request('GET', \sprintf('/api/v1/links/%s/stats/summary?%s', $link->getId(), $period), server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json',
        ]);
        self::assertResponseStatusCodeSame(200);
        $rest = Json::decode($client->getResponse()->getContent());

        StatementRecorder::reset();
        $this->post($client, $token, \sprintf(
            '{ linkSummaryReport(id: "/api/v1/links/%s/stats/summary", from: "2026-09-01T00:00:00Z", to: "2026-09-08T00:00:00Z") { generatedAt totalClicks } }',
            $link->getId(),
        ));
        $graphql = Json::map($this->data($client), 'linkSummaryReport');

        self::assertSame($rest['generatedAt'], $graphql['generatedAt'], 'the same cache entry, so the same moment');
        self::assertSame($rest['totalClicks'], $graphql['totalClicks']);
        self::assertSame([], array_filter(
            StatementRecorder::statements(),
            static fn (string $sql): bool => (bool) preg_match('/\bclicks\b/i', $sql),
        ), 'the second request read no clicks: it was served from the cache the first populated');
    }

    public function testADocumentIsChargedOneTokenPerRootSelection(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        $this->post($client, $token, '{ me { email } }');
        $afterOne = self::remaining($client);

        $this->post($client, $token, '{ a: me { email } b: me { email } c: me { email } }');
        $afterThree = self::remaining($client);

        self::assertSame(3, $afterOne - $afterThree, 'three root selections cost three tokens');
    }

    public function testIntrospectionAloneCostsOneWhateverItAsks(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        $this->post($client, $token, '{ me { email } }');
        $before = self::remaining($client);

        $this->post($client, $token, '{ __schema { queryType { name } } __type(name: "Link") { name } __typename }');
        $after = self::remaining($client);

        self::assertSame(1, $before - $after, 'introspection reads the schema, not the database');
    }

    public function testADocumentOverTheBudgetIsRefusedWholeAndResolvesNothing(): void
    {
        // Gate 2 round 1, finding 3: the refusal half of the cost model had no
        // test at all. The recorder is the proof that nothing ran — a document
        // refused before the executor issues no statement of its own.
        $client = self::createClient();
        $client->disableReboot();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        LinkFactory::createOne(['owner' => $owner, 'slug' => 'unread']);
        // before the first request: once a request has run, the service is
        // initialised and the test container refuses to replace it
        self::getContainer()->set('limiter.api_identity', self::limiterWithLimit(2));
        $token = $this->token($client, 'a@example.com');

        StatementRecorder::reset();
        $this->post($client, $token, '{ a: links { totalCount } b: links { totalCount } c: links { totalCount } }');

        // a document larger than the whole budget can never be paid for, so
        // it is refused rather than priced — and rather than failed open,
        // which is what the limiter's own exception used to cause
        self::assertResponseStatusCodeSame(429);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('more reads than the rate limit allows', Json::string(Json::decode($client->getResponse()->getContent()), 'detail'));
        self::assertSame([], array_filter(
            StatementRecorder::statements(),
            static fn (string $sql): bool => (bool) preg_match('/\blinks\b/i', $sql),
        ), 'no field was resolved: the refusal happened before the executor');
    }

    public function testADocumentWithinTheBudgetIsAnsweredWhereTheSameDocumentOverItIsNot(): void
    {
        // the pair matters: without it, a test asserting 429 could be passing
        // because the document was malformed rather than because it was priced
        $client = self::createClient();
        $client->disableReboot();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        LinkFactory::createOne(['owner' => $owner, 'slug' => 'read']);
        self::getContainer()->set('limiter.api_identity', self::limiterWithLimit(3));
        $token = $this->token($client, 'a@example.com');

        $document = '{ a: links { totalCount } b: links { totalCount } c: links { totalCount } }';

        $this->post($client, $token, $document);
        self::assertResponseStatusCodeSame(200, 'three selections against a budget of three');

        $this->post($client, $token, $document);
        self::assertResponseStatusCodeSame(429, 'and the same document again cannot be covered');
    }

    private static function limiterWithLimit(int $limit): RateLimiterFactoryInterface
    {
        return new RateLimiterFactory(['id' => 'api_identity', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage());
    }

    private static function remaining(KernelBrowser $client): int
    {
        $header = $client->getResponse()->headers->get('X-RateLimit-Remaining');
        self::assertIsString($header, 'the limiter reports what is left');

        return (int) $header;
    }
}
