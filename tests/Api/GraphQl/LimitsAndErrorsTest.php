<?php

declare(strict_types=1);

namespace App\Tests\Api\GraphQl;

use App\Shared\Api\GraphQlErrorBoundary;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\FailingStatement;
use App\Tests\Support\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec graphql-api "The schema bounds what one document may ask" and "Which
 * refusals answer in which shape".
 *
 * The boundary of design decision 5 in both directions: what the executor
 * decides is 200 with `errors`, what the firewall or the rate limiter decides
 * keeps its own status and a problem-details body.
 */
#[CoversNothing]
final class LimitsAndErrorsTest extends GraphQlTestCase
{
    public function testADocumentDeeperThanTheLimitIsRefusedByTheExecutor(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);

        $inner = 'name';
        for ($i = 0; $i < 14; ++$i) {
            $inner = 'ofType { '.$inner.' }';
        }
        $this->post($client, $this->token($client, 'a@example.com'), '{ __type(name: "Link") { fields { type { '.$inner.' } } } }');

        self::assertStringContainsString('Max query depth should be 10', $this->messages($client));
        self::assertArrayNotHasKey('data', Json::decode($client->getResponse()->getContent()), 'nothing was resolved');
    }

    public function testADocumentMoreComplexThanTheLimitIsRefusedByTheExecutor(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);

        // many root selections of a paginated collection: complexity counts
        // the whole selection, so this stays inside the depth limit and
        // exceeds the complexity one. Measured: 40 of these score 360 against
        // the declared 200, while 20 pass — so the limit is doing the refusing
        // rather than some other bound.
        $selection = 'links(first: 100) { edges { node { id slug targetUrl clickCount isActive createdAt } } }';
        $document = static fn (int $n): string => '{ '.implode(' ', array_map(static fn (int $i): string => "a$i: $selection", range(1, $n))).' }';

        $this->post($client, $this->token($client, 'a@example.com'), $document(40));
        self::assertStringContainsString('Max query complexity should be 200', $this->messages($client));

        $this->post($client, $this->token($client, 'a@example.com'), $document(20));
        self::assertArrayHasKey('a1', $this->data($client), 'a document inside the limit is answered');
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function refusedBeforeTheExecutor(): iterable
    {
        yield 'a body that is not a JSON object' => ['"a string"', 400, 'JSON object'];
        yield 'a query that is not a string' => ['{"query": 42}', 400, '"query" string'];
        yield 'variables that are not an object' => ['{"query":"{ me { email } }","variables":"x"}', 400, '"variables"'];
        yield 'variables that are a JSON list' => ['{"query":"{ me { email } }","variables":[1]}', 400, '"variables"'];
        yield 'variables that are an empty JSON list' => ['{"query":"{ me { email } }","variables":[]}', 400, '"variables"'];
        yield 'an unparseable document' => ['{"query":"{ me { email "}', 400, 'parsed'];
        yield 'two operations and no name' => ['{"query":"query A { me { email } } query B { me { email } }"}', 400, 'exactly one operation'];
    }

    #[DataProvider('refusedBeforeTheExecutor')]
    public function testAnUnpriceableRequestIsProblemDetailsNotAGraphQlError(string $rawBody, int $status, string $expected): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        $client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: $rawBody);

        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString($expected, Json::string(Json::decode($client->getResponse()->getContent()), 'detail'));
    }

    public function testARefusedReportParameterIsAGraphQlErrorNamingIt(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner]);

        $this->post(
            $client,
            $this->token($client, 'a@example.com'),
            \sprintf('{ linkTimeseriesReport(id: "/api/v1/links/%s/stats/timeseries", from: "2026-01-01T00:00:00Z", to: "2026-09-01T00:00:00Z", granularity: "hour") { granularity } }', $link->getId()),
        );

        // 200 with errors: decided by the executor, so GraphQL's own shape
        self::assertStringContainsString('granularity', $this->messages($client));
        self::assertStringStartsWith('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testAnUnexpectedInternalFailureLeaksNothing(): void
    {
        // Gate 2 round 1, finding 4: this used to trigger the same depth
        // refusal as the test above, so its assertions about SQL and class
        // names proved nothing. It now fails a real statement of a real
        // resolver, with a message carrying detail a caller must never see —
        // and that is how the leak it asserts against was found.
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner]);
        $token = $this->token($client, 'a@example.com');

        // one kernel for the whole test: the middleware is armed on the
        // container the next request will use, not on a rebuilt one
        $client->disableReboot();
        FailingStatement::failOn('FROM links t0');

        try {
            $this->post($client, $token, \sprintf('{ linkSummaryReport(id: "/api/v1/links/%s/stats/summary") { totalClicks } }', $link->getId()));
        } finally {
            FailingStatement::reset();
        }

        $errors = $this->errors($client);
        self::assertCount(1, $errors, 'the resolver failed rather than answering');
        self::assertSame(['linkSummaryReport' => null], Json::decode($client->getResponse()->getContent())['data'] ?? null, 'nothing was resolved');

        $body = (string) $client->getResponse()->getContent();
        self::assertSame(GraphQlErrorBoundary::GENERIC_MESSAGE, $errors[0]['message']);
        foreach (['Injected failure', 'SELECT ', 'FROM links', 'RuntimeException', 'App\\\\', '.php', '/vendor/'] as $leak) {
            self::assertStringNotContainsString($leak, $body, "the error leaked: $leak");
        }
        // the caller still learns which part of its own document failed
        self::assertSame(['linkSummaryReport'], $errors[0]['path'] ?? null);
    }
}
