<?php

declare(strict_types=1);

namespace App\Tests\Api\Contract;

use App\Auth\Entity\ApiKey;
use App\Tests\Api\Link\LinkApiTestCase;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Spec api-docs, "What an operation answers is what the document declares for
 * it" (change polish-api-and-openapi, design decision 4).
 *
 * One representative request per documented operation, plus the refusals: the
 * status the API actually returns must be declared for that operation, and the
 * response's media type must be the one the document gives for that status.
 * This is what makes the document worth trusting — asserting things about its
 * own shape proves nothing an integrator cares about.
 *
 * It is a sample, not a proof: it cannot show that the API never answers an
 * undocumented status, only that the answers it was asked for are documented.
 */
#[CoversNothing]
final class ApiContractTest extends LinkApiTestCase
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $operations = null;

    public function testEveryDocumentedOperationHasACase(): void
    {
        $client = self::createClient();
        $documented = array_keys(self::operations($client));
        $covered = array_values(array_unique(array_map(
            static fn (array $case): string => $case['operation'],
            iterator_to_array($this->cases(), false),
        )));
        sort($covered);

        self::assertSame($documented, $covered, 'an operation without a contract case is an unchecked claim');
    }

    public function testTheApiAnswersWhatTheDocumentDeclares(): void
    {
        $client = self::createClient();
        $operations = self::operations($client);
        $world = $this->world($client);

        foreach ($this->cases() as $case) {
            $uri = strtr($case['uri'], $world['tokens']);
            $token = null === $case['as'] ? null : $world['tokens'][$case['as']];
            $this->send($client, $case['method'], $uri, $token, $case['body'] ?? null, $case['contentType'] ?? null, $case['accept'] ?? null);

            $status = (string) $client->getResponse()->getStatusCode();
            // the expected status first: a refusal case that quietly succeeded
            // would otherwise pass, because its 200 is documented too (Gate 2
            // round 1, finding 6)
            self::assertSame((string) $case['expect'], $status, "{$case['name']}: expected {$case['expect']}");
            $declared = $operations[$case['operation']]['responses'] ?? [];
            self::assertArrayHasKey($status, $declared, "{$case['name']}: {$case['operation']} answered $status, which the document does not declare for it");

            /** @var list<string> $documentedTypes */
            $documentedTypes = array_map(strval(...), array_keys($declared[$status]['content'] ?? []));
            $actual = (string) $client->getResponse()->headers->get('Content-Type');
            if ([] === $documentedTypes) {
                self::assertSame('', $actual, "{$case['name']}: a response documented without content sent a body type");
                continue;
            }
            $matches = array_filter($documentedTypes, static fn (string $type): bool => str_starts_with($actual, $type));
            self::assertNotSame([], $matches, "{$case['name']}: answered $actual, documented ".implode(', ', $documentedTypes));
        }
    }

    public function testTheFiltersAndOrderingSelectWhatTheyDocument(): void
    {
        $client = self::createClient();
        $world = $this->world($client);
        $token = $world['tokens']['{owner}'];

        $this->api($client, $token, 'GET', '/api/v1/links?slug=contract');
        $slugs = self::slugs($this->decode($client));
        self::assertSame(['contract-off', 'contract-one', 'contract-two'], self::sorted($slugs), 'the fragment selects every slug containing it');

        $this->api($client, $token, 'GET', '/api/v1/links?isActive=false');
        self::assertSame(['contract-off'], self::slugs($this->decode($client)));

        $this->api($client, $token, 'GET', '/api/v1/links?order[clickCount]=desc');
        self::assertSame(['contract-two', 'contract-one'], \array_slice(self::slugs($this->decode($client)), 0, 2), 'most clicked first');

        $this->api($client, $token, 'GET', '/api/v1/links?order[clickCount]=asc&isActive=true');
        self::assertSame('contract-one', self::slugs($this->decode($client))[0]);
    }

    public function testTheReportParametersAreDeclaredOnTheReportOperations(): void
    {
        $client = self::createClient();
        $operations = self::operations($client);

        foreach ($operations as $name => $operation) {
            if (!str_contains($name, '/stats/')) {
                continue;
            }
            $parameters = array_column($operation['parameters'] ?? [], 'name');
            self::assertContains('includeBots', $parameters, "$name declares includeBots");
            // the global summary is all-time plus today: it has no period, and
            // the capability says so, so it declares none (FR-ANL-4)
            if (!str_ends_with($name, '/admin/stats/summary')) {
                foreach (['from', 'to'] as $expected) {
                    self::assertContains($expected, $parameters, "$name declares $expected");
                }
            }
            if (str_ends_with($name, 'timeseries')) {
                self::assertContains('granularity', $parameters, "$name declares granularity");
            }
            if (str_ends_with($name, 'countries') || str_ends_with($name, 'referrers') || str_ends_with($name, 'top-links')) {
                self::assertContains('limit', $parameters, "$name declares limit");
            }
        }
    }

    public function testTheKeyCapRefusalIsWhatTheDocumentDeclares(): void
    {
        $client = self::createClient();
        $operations = self::operations($client);
        UserFactory::createOne(['email' => 'owner@example.com']);
        $token = $this->token($client, 'owner@example.com');

        for ($i = 0; $i < ApiKey::MAX_ACTIVE_PER_USER; ++$i) {
            $this->api($client, $token, 'POST', '/api/v1/api-keys', ['name' => 'key '.$i]);
            self::assertResponseStatusCodeSame(201);
        }
        $this->api($client, $token, 'POST', '/api/v1/api-keys', ['name' => 'one too many']);

        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('409', $operations['POST /api/v1/api-keys']['responses'], 'the operation declares the refusal it just made');
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testTheAdministrativeCollectionFiltersTheSameWay(): void
    {
        $client = self::createClient();
        $world = $this->world($client);
        $admin = $world['tokens']['{admin}'];

        $this->api($client, $admin, 'GET', '/api/v1/admin/links?slug=contract');
        self::assertSame(['contract-off', 'contract-one', 'contract-two'], self::sorted(self::slugs($this->decode($client))), 'every owner’s links, filtered');

        $this->api($client, $admin, 'GET', '/api/v1/admin/links?slug=contract&isActive=true&order[clickCount]=desc');
        self::assertSame(['contract-two', 'contract-one'], self::slugs($this->decode($client)));

        $this->api($client, $admin, 'GET', '/api/v1/admin/links?slug=contract&order[createdAt]=asc');
        $ascending = self::slugs($this->decode($client));
        $this->api($client, $admin, 'GET', '/api/v1/admin/links?slug=contract&order[createdAt]=desc');
        self::assertSame(array_reverse($ascending), self::slugs($this->decode($client)), 'the creation order reverses');
    }

    public function testTheOwnerCollectionOrdersByCreationToo(): void
    {
        $client = self::createClient();
        $world = $this->world($client);
        $token = $world['tokens']['{owner}'];

        $this->api($client, $token, 'GET', '/api/v1/links?slug=contract&order[createdAt]=asc');
        $ascending = self::slugs($this->decode($client));
        $this->api($client, $token, 'GET', '/api/v1/links?slug=contract&order[createdAt]=desc');

        self::assertSame(array_reverse($ascending), self::slugs($this->decode($client)));
    }

    public function testTheRateLimitRefusalIsWhatTheDocumentDeclares(): void
    {
        // the limiter is drivable here: its factory is swapped for one with a
        // window of three, the same way tests/Api/Auth/ApiRateLimitTest does,
        // so this case is exercised rather than recorded as unexercised
        $client = self::createClient();
        $client->disableReboot();
        $operations = self::operations($client);
        $user = UserFactory::createOne(['email' => 'owner@example.com']);
        $key = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($key)->create(['owner' => $user]);
        self::getContainer()->set('limiter.api_identity', new RateLimiterFactory(
            ['id' => 'api_identity', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));

        for ($i = 0; $i < 3; ++$i) {
            $this->withKey($client, $key, 'GET', '/api/v1/me');
            self::assertResponseIsSuccessful();
        }
        $this->withKey($client, $key, 'GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(429);
        $declared = $operations['GET /api/v1/me']['responses']['429'] ?? null;
        self::assertIsArray($declared, 'the operation declares the refusal it just made');
        self::assertArrayHasKey('Retry-After', $declared['headers'] ?? []);
        self::assertGreaterThanOrEqual(1, (int) $client->getResponse()->headers->get('Retry-After'), 'and sends the header it documents');
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    private function withKey(KernelBrowser $client, string $key, string $method, string $uri): void
    {
        $client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * One case per documented operation, then the refusals. `{owner}` and its
     * siblings are replaced with what the fixtures made.
     *
     * @return iterable<array{name: string, expect: int, operation: string, method: string, uri: string, as: ?string, body?: array<string, mixed>|string, contentType?: string, accept?: string}>
     */
    private function cases(): iterable
    {
        // the happy path of every operation
        yield ['name' => 'register', 'expect' => 201, 'operation' => 'POST /api/v1/auth/register', 'method' => 'POST', 'uri' => '/api/v1/auth/register', 'as' => null, 'body' => ['email' => 'fresh@example.com', 'password' => 'correct-horse-battery-staple']];
        yield ['name' => 'token', 'expect' => 200, 'operation' => 'POST /api/v1/auth/token', 'method' => 'POST', 'uri' => '/api/v1/auth/token', 'as' => null, 'body' => ['email' => 'owner@example.com', 'password' => UserFactory::PASSWORD]];
        yield ['name' => 'me', 'expect' => 200, 'operation' => 'GET /api/v1/me', 'method' => 'GET', 'uri' => '/api/v1/me', 'as' => '{owner}'];
        yield ['name' => 'links', 'expect' => 200, 'operation' => 'GET /api/v1/links', 'method' => 'GET', 'uri' => '/api/v1/links', 'as' => '{owner}'];
        yield ['name' => 'create link', 'expect' => 201, 'operation' => 'POST /api/v1/links', 'method' => 'POST', 'uri' => '/api/v1/links', 'as' => '{owner}', 'body' => ['targetUrl' => 'https://example.com/new']];
        yield ['name' => 'link', 'expect' => 200, 'operation' => 'GET /api/v1/links/{id}', 'method' => 'GET', 'uri' => '/api/v1/links/{link}', 'as' => '{owner}'];
        yield ['name' => 'patch link', 'expect' => 200, 'operation' => 'PATCH /api/v1/links/{id}', 'method' => 'PATCH', 'uri' => '/api/v1/links/{link}', 'as' => '{owner}', 'body' => ['isActive' => true]];
        // the QR operation produces images, so the case has to ask for one —
        // with the JSON default it answered 406, and before every case named
        // its expected status that passed as documented (round 1, finding 6)
        yield ['name' => 'qr', 'expect' => 200, 'operation' => 'GET /api/v1/links/{id}/qr', 'method' => 'GET', 'uri' => '/api/v1/links/{link}/qr', 'as' => '{owner}', 'accept' => 'image/svg+xml'];
        foreach (['summary', 'timeseries', 'countries', 'devices', 'referrers', 'variants'] as $report) {
            yield ['name' => "link $report", 'expect' => 200, 'operation' => "GET /api/v1/links/{id}/stats/$report", 'method' => 'GET', 'uri' => "/api/v1/links/{link}/stats/$report", 'as' => '{owner}'];
        }
        yield ['name' => 'keys', 'expect' => 200, 'operation' => 'GET /api/v1/api-keys', 'method' => 'GET', 'uri' => '/api/v1/api-keys', 'as' => '{owner}'];
        yield ['name' => 'create key', 'expect' => 201, 'operation' => 'POST /api/v1/api-keys', 'method' => 'POST', 'uri' => '/api/v1/api-keys', 'as' => '{owner}', 'body' => ['name' => 'contract']];
        yield ['name' => 'revoke key', 'expect' => 204, 'operation' => 'DELETE /api/v1/api-keys/{id}', 'method' => 'DELETE', 'uri' => '/api/v1/api-keys/{key}', 'as' => '{owner}'];
        yield ['name' => 'admin users', 'expect' => 200, 'operation' => 'GET /api/v1/admin/users', 'method' => 'GET', 'uri' => '/api/v1/admin/users', 'as' => '{admin}'];
        yield ['name' => 'block', 'expect' => 200, 'operation' => 'POST /api/v1/admin/users/{id}/block', 'method' => 'POST', 'uri' => '/api/v1/admin/users/{stranger-id}/block', 'as' => '{admin}'];
        yield ['name' => 'unblock', 'expect' => 200, 'operation' => 'POST /api/v1/admin/users/{id}/unblock', 'method' => 'POST', 'uri' => '/api/v1/admin/users/{stranger-id}/unblock', 'as' => '{admin}'];
        yield ['name' => 'admin links', 'expect' => 200, 'operation' => 'GET /api/v1/admin/links', 'method' => 'GET', 'uri' => '/api/v1/admin/links', 'as' => '{admin}'];
        foreach (['summary', 'timeseries', 'top-links'] as $report) {
            yield ['name' => "admin $report", 'expect' => 200, 'operation' => "GET /api/v1/admin/stats/$report", 'method' => 'GET', 'uri' => "/api/v1/admin/stats/$report", 'as' => '{admin}'];
        }
        // and the refusals
        yield ['name' => 'anonymous', 'expect' => 401, 'operation' => 'GET /api/v1/links', 'method' => 'GET', 'uri' => '/api/v1/links', 'as' => null];
        yield ['name' => 'stranger', 'expect' => 403, 'operation' => 'GET /api/v1/links/{id}', 'method' => 'GET', 'uri' => '/api/v1/links/{other-link}', 'as' => '{owner}'];
        yield ['name' => 'not an admin', 'expect' => 403, 'operation' => 'GET /api/v1/admin/users', 'method' => 'GET', 'uri' => '/api/v1/admin/users', 'as' => '{owner}'];
        yield ['name' => 'unknown id', 'expect' => 404, 'operation' => 'GET /api/v1/links/{id}', 'method' => 'GET', 'uri' => '/api/v1/links/{nothing}', 'as' => '{owner}'];
        yield ['name' => 'invalid body', 'expect' => 422, 'operation' => 'POST /api/v1/links', 'method' => 'POST', 'uri' => '/api/v1/links', 'as' => '{owner}', 'body' => ['targetUrl' => 'not-a-url']];
        yield ['name' => 'bad credentials', 'expect' => 401, 'operation' => 'POST /api/v1/auth/token', 'method' => 'POST', 'uri' => '/api/v1/auth/token', 'as' => null, 'body' => ['email' => 'owner@example.com', 'password' => 'wrong']];
        yield ['name' => 'unreadable credentials', 'expect' => 400, 'operation' => 'POST /api/v1/auth/token', 'method' => 'POST', 'uri' => '/api/v1/auth/token', 'as' => null, 'body' => '{'];
        yield ['name' => 'blocked account', 'expect' => 403, 'operation' => 'POST /api/v1/auth/token', 'method' => 'POST', 'uri' => '/api/v1/auth/token', 'as' => null, 'body' => ['email' => 'blocked@example.com', 'password' => UserFactory::PASSWORD]];
        // json_login answers before content negotiation, so an Accept it cannot
        // satisfy is not refused — the document says so (Gate 2 round 1, finding 3)
        yield ['name' => 'token with an incompatible Accept', 'expect' => 200, 'operation' => 'POST /api/v1/auth/token', 'method' => 'POST', 'uri' => '/api/v1/auth/token', 'as' => null, 'body' => ['email' => 'owner@example.com', 'password' => UserFactory::PASSWORD], 'accept' => 'text/csv'];
        yield ['name' => 'unsupported media type', 'expect' => 415, 'operation' => 'POST /api/v1/links', 'method' => 'POST', 'uri' => '/api/v1/links', 'as' => '{owner}', 'body' => 'targetUrl=https://example.com', 'contentType' => 'text/plain'];
        // the report parameters are refused by the analytics rules, one value
        // at a time and across values (Gate 2 round 2, finding 1)
        yield ['name' => 'a bound that is not a timestamp', 'expect' => 422, 'operation' => 'GET /api/v1/links/{id}/stats/summary', 'method' => 'GET', 'uri' => '/api/v1/links/{link}/stats/summary?from=yesterday', 'as' => '{owner}'];
        yield ['name' => 'a limit out of range', 'expect' => 422, 'operation' => 'GET /api/v1/links/{id}/stats/countries', 'method' => 'GET', 'uri' => '/api/v1/links/{link}/stats/countries?limit=0', 'as' => '{owner}'];
        yield ['name' => 'an inverted period', 'expect' => 422, 'operation' => 'GET /api/v1/links/{id}/stats/timeseries', 'method' => 'GET', 'uri' => '/api/v1/links/{link}/stats/timeseries?from=2026-09-08T00:00:00Z&to=2026-09-01T00:00:00Z', 'as' => '{owner}'];
        yield ['name' => 'hourly over too long a period', 'expect' => 422, 'operation' => 'GET /api/v1/links/{id}/stats/timeseries', 'method' => 'GET', 'uri' => '/api/v1/links/{link}/stats/timeseries?from=2026-06-01T00:00:00Z&to=2026-09-01T00:00:00Z&granularity=hour', 'as' => '{owner}'];
        yield ['name' => 'a global report parameter', 'expect' => 422, 'operation' => 'GET /api/v1/admin/stats/top-links', 'method' => 'GET', 'uri' => '/api/v1/admin/stats/top-links?limit=500', 'as' => '{admin}'];
        // a collection's parameters are refused by the framework, with 400
        yield ['name' => 'a page that is not a number', 'expect' => 400, 'operation' => 'GET /api/v1/links', 'method' => 'GET', 'uri' => '/api/v1/links?page=abc', 'as' => '{owner}'];
        yield ['name' => 'a filter value the schema refuses', 'expect' => 400, 'operation' => 'GET /api/v1/links', 'method' => 'GET', 'uri' => '/api/v1/links?isActive=maybe', 'as' => '{owner}'];
        yield ['name' => 'an administrative page that is not a number', 'expect' => 400, 'operation' => 'GET /api/v1/admin/users', 'method' => 'GET', 'uri' => '/api/v1/admin/users?page=abc', 'as' => '{admin}'];
        // the authenticator declines a body it cannot read and no controller
        // runs, so the kernel answers 404 — not the 415 an operation would
        yield ['name' => 'the token endpoint with an unsupported media type', 'expect' => 404, 'operation' => 'POST /api/v1/auth/token', 'method' => 'POST', 'uri' => '/api/v1/auth/token', 'as' => null, 'body' => 'email=a@b.c', 'contentType' => 'text/plain'];
        yield ['name' => 'an image format that is not one', 'expect' => 422, 'operation' => 'GET /api/v1/links/{id}/qr', 'method' => 'GET', 'uri' => '/api/v1/links/{link}/qr?format=tiff', 'as' => '{owner}', 'accept' => 'image/svg+xml'];
        yield ['name' => 'unacceptable media type', 'expect' => 406, 'operation' => 'GET /api/v1/me', 'method' => 'GET', 'uri' => '/api/v1/me', 'as' => '{owner}', 'accept' => 'text/csv'];

        // last of all: it removes the link every case above needs
        yield ['name' => 'delete link', 'expect' => 204, 'operation' => 'DELETE /api/v1/links/{id}', 'method' => 'DELETE', 'uri' => '/api/v1/links/{link}', 'as' => '{owner}'];
    }

    /**
     * @return array{tokens: array<string, string>}
     */
    private function world(KernelBrowser $client): array
    {
        $owner = UserFactory::createOne(['email' => 'owner@example.com']);
        $stranger = UserFactory::createOne(['email' => 'stranger@example.com']);
        UserFactory::new()->blocked()->create(['email' => 'blocked@example.com']);
        UserFactory::new()->admin()->create(['email' => 'root@example.com']);
        $link = LinkFactory::new()->limited(100, 3)->create(['owner' => $owner, 'slug' => 'contract-one']);
        LinkFactory::new()->limited(100, 9)->create(['owner' => $owner, 'slug' => 'contract-two']);
        LinkFactory::new()->inactive()->create(['owner' => $owner, 'slug' => 'contract-off']);
        $others = LinkFactory::createOne(['owner' => $stranger, 'slug' => 'not-yours']);

        $ownerToken = $this->token($client, 'owner@example.com');
        $this->api($client, $ownerToken, 'POST', '/api/v1/api-keys', ['name' => 'contract fixture']);
        $key = $this->decode($client);

        return ['tokens' => [
            '{owner}' => $ownerToken,
            '{admin}' => $this->token($client, 'root@example.com'),
            '{link}' => (string) $link->getId(),
            '{other-link}' => (string) $others->getId(),
            '{key}' => (string) $key['id'],
            '{stranger-id}' => (string) $stranger->getId(),
            '{nothing}' => Uuid::v7()->toRfc4122(),
        ]];
    }

    /**
     * @param array<string, mixed>|string|null $body
     */
    private function send(KernelBrowser $client, string $method, string $uri, ?string $token, array|string|null $body, ?string $contentType = null, ?string $accept = null): void
    {
        $server = ['HTTP_ACCEPT' => $accept ?? 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if (null !== $body) {
            $server['CONTENT_TYPE'] = $contentType ?? ('PATCH' === $method ? 'application/merge-patch+json' : 'application/json');
        }
        $content = \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body;
        $client->request($method, $uri, server: $server, content: $content);
    }

    /**
     * @param array<string, mixed> $collection
     *
     * @return list<string>
     */
    private static function slugs(array $collection): array
    {
        /** @var list<array<string, mixed>> $members */
        $members = $collection['member'] ?? $collection['items'] ?? [];

        return array_map(static fn (array $link): string => (string) $link['slug'], $members);
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<string>
     */
    private static function sorted(array $slugs): array
    {
        sort($slugs);

        return $slugs;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function operations(KernelBrowser $client): array
    {
        if (null === self::$operations) {
            $client->request('GET', '/api/docs.json');
            self::assertResponseIsSuccessful();
            /** @var array<string, mixed> $document */
            $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $operations = [];
            /** @var array<string, array<string, mixed>> $paths */
            $paths = $document['paths'] ?? [];
            foreach ($paths as $path => $item) {
                foreach ($item as $method => $operation) {
                    if (\in_array($method, ['get', 'post', 'patch', 'put', 'delete'], true) && \is_array($operation)) {
                        $operations[strtoupper($method).' '.$path] = $operation;
                    }
                }
            }
            ksort($operations);
            self::$operations = $operations;
        }

        return self::$operations;
    }

    protected function tearDown(): void
    {
        self::$operations = null;
        parent::tearDown();
    }
}
