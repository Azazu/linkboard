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
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return self::refused('The request body must be a JSON object.');
        }
        $query = $body['query'] ?? null;
        if (!\is_string($query)) {
            return self::refused('The request body must carry a "query" string.');
        }
        if (\array_key_exists('variables', $body) && null !== $body['variables'] && !\is_array($body['variables'])) {
            return self::refused('"variables" must be an object.');
        }

        try {
            $document = Parser::parse($query, ['noLocation' => true]);
        } catch (\Throwable) {
            return self::refused('The query could not be parsed.');
        }

        $name = $body['operationName'] ?? null;
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
        try {
            $selections = self::count($operation->selectionSet, $fragments, []);
            // introspection reads the schema, not the database: one token
            // however many of these a document asks for
            $introspectionOnly = self::isAllIntrospection($operation->selectionSet, $fragments, []);
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
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param list<string>                          $expanding the spreads already being followed, so a cycle is refused rather than followed for ever
     *
     * @throws \OverflowException on a fragment cycle
     */
    private static function count(SelectionSetNode $set, array $fragments, array $expanding): int
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
                $total += self::count($fragment->selectionSet, $fragments, [...$expanding, $name]);
                continue;
            }
            if ($selection instanceof InlineFragmentNode) {
                $total += self::count($selection->selectionSet, $fragments, $expanding);
                continue;
            }
            // a field: aliases are separate selections, and @skip/@include are
            // deliberately not evaluated
            ++$total;
        }

        return $total;
    }

    /**
     * Carries its own cycle guard rather than relying on `count()` having run
     * first and thrown: a defence that depends on the order two private
     * methods are called in is a defence that breaks when somebody reorders
     * them.
     *
     * @param array<string, FragmentDefinitionNode> $fragments
     * @param list<string>                          $expanding
     *
     * @throws \OverflowException on a fragment cycle
     */
    private static function isAllIntrospection(SelectionSetNode $set, array $fragments, array $expanding): bool
    {
        foreach ($set->selections as $selection) {
            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                if (\in_array($name, $expanding, true)) {
                    throw new \OverflowException(\sprintf('The fragment "%s" spreads itself.', $name));
                }
                $fragment = $fragments[$name] ?? null;
                if (null === $fragment || !self::isAllIntrospection($fragment->selectionSet, $fragments, [...$expanding, $name])) {
                    return false;
                }
                continue;
            }
            if ($selection instanceof InlineFragmentNode) {
                if (!self::isAllIntrospection($selection->selectionSet, $fragments, $expanding)) {
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
