<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\RateLimit\GraphQlCost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spec graphql-api "A GraphQL document costs what the work costs" and "An
 * unusable request is refused before it is priced".
 *
 * Every case here is a place a hand-written counter undercharges — which is
 * why the algorithm is written down in design decision 3 and why each of its
 * clauses has a case rather than a sentence (change stretch-graphql, Gate 1
 * round 1, finding 4).
 */
#[CoversClass(GraphQlCost::class)]
final class GraphQlCostTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function priced(): iterable
    {
        yield 'one root selection' => ['{ me { email } }', 1];
        yield 'three root selections' => ['{ link(id: "a") { slug } me { email } links { edges { node { slug } } } }', 3];
        yield 'two aliases of one field are two reads' => ['{ a: me { email } b: me { email } }', 2];
        yield 'the same field twice, unaliased, is two selections' => ['{ me { email } me { email } }', 2];
        yield 'a named root fragment contributes what it names' => ['{ ...roots } fragment roots on Query { me { email } links { totalCount } }', 2];
        yield 'an inline root fragment contributes what it names' => ['{ ... on Query { me { email } links { totalCount } } }', 2];
        yield 'a skipped selection is still paid for' => ['{ me @skip(if: true) { email } link(id: "a") { slug } }', 2];
        yield 'introspection alone costs one, however much of it' => ['{ __schema { queryType { name } } __type(name: "Link") { name } __typename }', 1];
        yield 'introspection beside a real field is priced normally' => ['{ __schema { queryType { name } } me { email } }', 2];
        yield 'the named operation is the one charged' => ['query A { me { email } } query B { me { email } link(id: "x") { slug } }', 1];
    }

    #[DataProvider('priced')]
    public function testADocumentCostsItsRootSelections(string $query, int $expected): void
    {
        $body = ['query' => $query];
        if (str_starts_with($query, 'query A')) {
            $body['operationName'] = 'A';
        }

        $cost = GraphQlCost::of(self::request($body));

        self::assertFalse($cost->isRefused(), (string) $cost->refusal);
        self::assertSame($expected, $cost->tokens);
    }

    public function testTheNamedOperationIsChargedAndNotTheOther(): void
    {
        $document = 'query A { me { email } } query B { me { email } link(id: "x") { slug } }';

        self::assertSame(1, GraphQlCost::of(self::request(['query' => $document, 'operationName' => 'A']))->tokens);
        self::assertSame(2, GraphQlCost::of(self::request(['query' => $document, 'operationName' => 'B']))->tokens);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, string}>
     */
    public static function unpriceable(): iterable
    {
        yield 'a body that is not a JSON object' => ['"just a string"', 'JSON object'];
        yield 'a query that is not a string' => [['query' => 42], '"query" string'];
        yield 'variables that are not an object' => [['query' => '{ me { email } }', 'variables' => 'nope'], '"variables"'];
        yield 'variables that are a JSON list' => ['{"query":"{ me { email } }","variables":[1]}', '"variables"'];
        yield 'variables that are a list of objects' => ['{"query":"{ me { email } }","variables":[{"n":1}]}', '"variables"'];
        yield 'an operationName that is not a string' => [['query' => '{ me { email } }', 'operationName' => 7], '"operationName"'];
        yield 'a document that does not parse' => [['query' => '{ me { email '], 'parsed'];
        yield 'a document with no operation' => [['query' => 'fragment f on Query { me { email } }'], 'exactly one operation'];
        yield 'two operations and no operationName' => [['query' => 'query A { me { email } } query B { me { email } }'], 'exactly one operation'];
        yield 'an operationName naming nothing' => [['query' => 'query A { me { email } }', 'operationName' => 'Z'], 'no operation named'];
        yield 'a fragment cycle' => [['query' => '{ ...a } fragment a on Query { ...b } fragment b on Query { ...a }'], 'spreads itself'];
        yield 'an undefined fragment' => [['query' => '{ ...missing }'], 'undefined fragment'];
        yield 'an operation selecting nothing' => [['query' => 'query { ... on Query { } }'], 'parsed'];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('unpriceable')]
    public function testAnUnpriceableRequestIsRefusedAndNamesWhy(array|string $body, string $expected): void
    {
        $cost = GraphQlCost::of(self::request($body));

        self::assertTrue($cost->isRefused(), 'the request should not have been priced');
        self::assertStringContainsString($expected, (string) $cost->refusal);
        self::assertSame(0, $cost->tokens, 'a refused request consumes nothing');
    }

    public function testAnEmptyVariableSetIsAccepted(): void
    {
        // `{}` and `[]` decode to the same PHP array, and an empty variable
        // set is a legitimate thing to send: there is nothing to misread in it
        foreach (['{"query":"{ me { email } }","variables":{}}', '{"query":"{ me { email } }","variables":[]}'] as $body) {
            $cost = GraphQlCost::of(self::request($body));
            self::assertFalse($cost->isRefused(), (string) $cost->refusal);
        }
    }

    public function testAFragmentTreeThatDoublesAtEveryLevelIsPricedPromptly(): void
    {
        // Gate 2 round 1, finding 1: without memoisation this visits 2^n
        // selections. Measured before the fix — 22 fragments, 860 bytes, 1.39
        // seconds and 2 097 152 tokens; the shape is acyclic and valid, so the
        // cycle guard never saw it, and this runs before API Platform's
        // complexity ceiling could.
        $fragments = '';
        $levels = 40;
        for ($i = 0; $i < $levels; ++$i) {
            $next = $i === $levels - 1 ? 'me { email }' : \sprintf('...F%d ...F%d', $i + 1, $i + 1);
            $fragments .= \sprintf(' fragment F%d on Query { %s }', $i, $next);
        }

        $started = microtime(true);
        $cost = GraphQlCost::of(self::request(['query' => '{ ...F0 }'.$fragments]));
        $elapsed = microtime(true) - $started;

        self::assertTrue($cost->isRefused(), 'a document asking for more reads than the budget can cover is refused');
        self::assertStringContainsString('more than '.GraphQlCost::MAX_TOKENS, (string) $cost->refusal);
        self::assertLessThan(1.0, $elapsed, \sprintf('pricing took %.2fs: the counter is expanding every occurrence again', $elapsed));
    }

    public function testACostAtTheCeilingIsStillPriced(): void
    {
        $document = '{ '.implode(' ', array_map(static fn (int $i): string => "a$i: me { email }", range(1, GraphQlCost::MAX_TOKENS))).' }';

        $cost = GraphQlCost::of(self::request(['query' => $document]));

        self::assertFalse($cost->isRefused(), (string) $cost->refusal);
        self::assertSame(GraphQlCost::MAX_TOKENS, $cost->tokens);
    }

    public function testARequestThatIsNotGraphQlCostsOne(): void
    {
        $cost = GraphQlCost::ofOneRequest();

        self::assertFalse($cost->isRefused());
        self::assertSame(1, $cost->tokens);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private static function request(array|string $body): Request
    {
        return Request::create('/api/v1/graphql', 'POST', content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));
    }
}
