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

    private static function remaining(KernelBrowser $client): int
    {
        $header = $client->getResponse()->headers->get('X-RateLimit-Remaining');
        self::assertIsString($header, 'the limiter reports what is left');

        return (int) $header;
    }
}
