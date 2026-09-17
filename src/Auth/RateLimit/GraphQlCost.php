<?php

declare(strict_types=1);

namespace App\Auth\RateLimit;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\Parser;
use Symfony\Component\HttpFoundation\Request;

/**
 * What one GraphQL request costs in tokens of the caller's per-identity budget
 * (change stretch-graphql, design decision 3).
 *
 * One root selection is one logical read: `{ link(…) {…} linkSummaryReport(…)
 * {…} }` is two reads and costs two, exactly what the same two REST calls
 * cost. Without this a document asking for fifty reports would cost what
 * `GET /api/v1/me` costs, because the limiter charges once per HTTP request.
 *
 * The count is taken from the document as parsed, never from its text, and the
 * places a hand-written counter undercharges are each decided here rather than
 * left to chance: fragments are expanded, aliases count separately, `@skip`
 * and `@include` are not evaluated — a directive read at execution time cannot
 * lower a price charged before it — and a document that cannot be priced is
 * refused rather than waved through at one token.
 */
final readonly class GraphQlCost
{
    /**
     * The most a document may cost before it is refused rather than priced.
     *
     * A document costing more than the whole per-minute budget can never be
     * served, so pricing it precisely is work done for nothing — and the
     * pricing itself is where that work would be spent (Gate 2 round 1,
     * finding 1). The counter saturates here and refuses.
     */
    public const int MAX_TOKENS = 1000;

    public function __construct(
        /** The reason a document could not be priced, or null when it could. */
        public ?string $refusal,
        /** Tokens to consume; meaningless when `refusal` is set. */
        public int $tokens,
    ) {
    }

    /** A request that is not GraphQL at all: one token, as every REST call. */
    public static function ofOneRequest(): self
    {
        return new self(null, 1);
    }

    public static function refused(string $why): self
    {
        return new self($why, 0);
    }

    public function isRefused(): bool
    {
        return null !== $this->refusal;
    }

    /**
     * The cost of a GraphQL request, or a refusal naming what made it
     * unpriceable.
     */
    public static function of(Request $request): self
    {
        // Decoded as objects rather than associative arrays: with `true` as the
        // second argument `{}` and `[]` both become an empty PHP array, and
        // telling a JSON object from a JSON list is exactly what this has to
        // do. `array_is_list()` cannot recover the difference once it is gone
        // (Gate 2 confirmation 1, finding 2).
        try {
            $body = json_decode($request->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::refused('The request body must be a JSON object.');
        }
        if (!$body instanceof \stdClass) {
            return self::refused('The request body must be a JSON object.');
        }
        $query = $body->query ?? null;
        if (!\is_string($query)) {
            return self::refused('The request body must carry a "query" string.');
        }
        $variables = $body->variables ?? null;
        if (null !== $variables && !$variables instanceof \stdClass) {
            return self::refused('"variables" must be a JSON object, and a JSON list is not one.');
        }

        try {
            $document = Parser::parse($query, ['noLocation' => true]);
        } catch (\Throwable) {
            return self::refused('The query could not be parsed.');
        }

        $name = $body->operationName ?? null;
        if (null !== $name && !\is_string($name)) {
            return self::refused('"operationName" must be a string.');
        }

        $operation = self::select($document, $name);
        if (null === $operation) {
            return self::refused(null === $name
                ? 'The document must carry exactly one operation, or name the one to run.'
                : \sprintf('The document defines no operation named "%s".', $name));
        }

        $fragments = self::fragments($document);
        $memo = [];
        $introspectionMemo = [];
        try {
            $selections = self::count($operation->selectionSet, $fragments, [], $memo);
            // the ceiling is applied BEFORE the second walk, so a document
            // refused for its size is never traversed again
            if ($selections > self::MAX_TOKENS) {
                return self::refused(\sprintf('The document asks for more than %d reads.', self::MAX_TOKENS));
            }
            // introspection reads the schema, not the database: one token
            // however many of these a document asks for
            $introspectionOnly = self::isAllIntrospection($operation->selectionSet, $fragments, [], $introspectionMemo);
        } catch (\OverflowException $e) {
            return self::refused($e->getMessage());
        }

        if (0 === $selections) {
            return self::refused('The operation selects nothing.');
        }

        return new self(null, $introspectionOnly ? 1 : $selections);
    }

    private static function select(DocumentNode $document, ?string $name): ?OperationDefinitionNode
    {
        $operations = [];
        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode) {
                $operations[] = $definition;
            }
        }

        if (null !== $name) {
            foreach ($operations as $operation) {
                if ($name === $operation->name?->value) {
                    return $operation;
                }
            }

            return null;
        }

        return 1 === \count($operations) ? $operations[0] : null;
    }

    /**
     * @return array<string, FragmentDefinitionNode>
     */
    private static function fragments(DocumentNode $document): array
    {
        $fragments = [];
        foreach ($document->definitions as $definition) {
            if ($definition instanceof FragmentDefinitionNode) {
                $fragments[$definition->name->value] = $definition;
            }
        }

        return $fragments;
    }

    /**
     * Root selections, with fragments expanded.
     *
     * Two properties make this safe to run before anything else does, and both
     * were added because the first version had neither (Gate 2 round 1,
     * finding 1):
     *
     * - **each fragment is counted once**, its cost memoised. Without that, a
     *   document where `F0` spreads `F1` twice, `F1` spreads `F2` twice and so
     *   on re-expands every occurrence: measured, 22 such fragments in an
     *   860-byte document made this visit 2 097 152 selections in 1.39 s, and
     *   a few more would have taken minutes and then overflowed the addition.
     *   It runs on `LoginSuccessEvent`, before API Platform's complexity
     *   validation, so that ceiling could not have saved the worker;
     * - **the total saturates** at `MAX_TOKENS + 1` and stops adding, so the
     *   arithmetic cannot overflow however the fragments multiply.
     *
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param list<string>                          $expanding the spreads already being followed, so a cycle is refused rather than followed for ever
     * @param array<string, int>                    $memo      each fragment's cost, computed once
     *
     * @throws \OverflowException on a fragment cycle or an undefined fragment
     */
    private static function count(SelectionSetNode $set, array $fragments, array $expanding, array &$memo): int
    {
        $total = 0;
        foreach ($set->selections as $selection) {
            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                if (\in_array($name, $expanding, true)) {
                    throw new \OverflowException(\sprintf('The fragment "%s" spreads itself.', $name));
                }
                $fragment = $fragments[$name] ?? null;
                if (null === $fragment) {
                    throw new \OverflowException(\sprintf('The document spreads an undefined fragment "%s".', $name));
                }
                if (!\array_key_exists($name, $memo)) {
                    $memo[$name] = self::count($fragment->selectionSet, $fragments, [...$expanding, $name], $memo);
                }
                $total += $memo[$name];
            } elseif ($selection instanceof InlineFragmentNode) {
                $total += self::count($selection->selectionSet, $fragments, $expanding, $memo);
            } else {
                // a field: aliases are separate selections, and @skip/@include
                // are deliberately not evaluated
                ++$total;
            }

            if ($total > self::MAX_TOKENS) {
                return self::MAX_TOKENS + 1;
            }
        }

        return $total;
    }

    /**
     * Carries its own cycle guard rather than relying on `count()` having run
     * first and thrown: a defence that depends on the order two private
     * methods are called in is a defence that breaks when somebody reorders
     * them. It memoises for the same reason `count()` does — an
     * introspection-only document of the doubling shape would otherwise walk
     * every expanded occurrence here instead, which is the same denial of
     * service one method further along (Gate 2 confirmation 1, finding 1).
     *
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param list<string>                          $expanding
     * @param array<string, bool>                   $memo      each fragment's verdict, decided once
     *
     * @throws \OverflowException on a fragment cycle
     */
    private static function isAllIntrospection(SelectionSetNode $set, array $fragments, array $expanding, array &$memo): bool
    {
        foreach ($set->selections as $selection) {
            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                if (\in_array($name, $expanding, true)) {
                    throw new \OverflowException(\sprintf('The fragment "%s" spreads itself.', $name));
                }
                $fragment = $fragments[$name] ?? null;
                if (null === $fragment) {
                    return false;
                }
                if (!\array_key_exists($name, $memo)) {
                    $memo[$name] = self::isAllIntrospection($fragment->selectionSet, $fragments, [...$expanding, $name], $memo);
                }
                if (!$memo[$name]) {
                    return false;
                }
                continue;
            }
            if ($selection instanceof InlineFragmentNode) {
                if (!self::isAllIntrospection($selection->selectionSet, $fragments, $expanding, $memo)) {
                    return false;
                }
                continue;
            }
            $name = $selection->name->value ?? '';
            if (!str_starts_with($name, '__')) {
                return false;
            }
        }

        return true;
    }
}
