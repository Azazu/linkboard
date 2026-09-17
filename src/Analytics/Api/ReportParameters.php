<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\Operation;
use Symfony\Component\HttpFoundation\Request;

/**
 * The parameters a caller supplied for a report, whichever protocol carried
 * them (change stretch-graphql, design decision 2).
 *
 * REST puts them in the query string; GraphQL puts them in the operation's
 * arguments, which API Platform passes in the resolver context under `args`.
 * `ReportRequestFactory` takes the map either produces, so one parser applies
 * one set of rules and writes one cache key — a report asked for through
 * GraphQL is the same report, not a similar one.
 */
final class ReportParameters
{
    /** The names a report accepts; anything else a caller sends is not ours to read. */
    private const array NAMES = ['from', 'to', 'granularity', 'limit', 'includeBots'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $context the state provider's context
     *
     * @return array<string, mixed> by parameter name, absent where not supplied
     */
    public static function fromContext(array $context): array
    {
        $request = $context['request'] ?? null;
        if ($request instanceof Request) {
            return self::pick($request->query->all());
        }

        // GraphQL: the operation's own arguments
        $args = $context['args'] ?? null;

        return \is_array($args) ? self::pick($args) : [];
    }

    /**
     * @param array<array-key, mixed> $supplied
     *
     * @return array<string, mixed>
     */
    private static function pick(array $supplied): array
    {
        $values = [];
        foreach (self::NAMES as $name) {
            if (\array_key_exists($name, $supplied)) {
                $values[$name] = $supplied[$name];
            }
        }

        return $values;
    }

    /** Whether this operation is being resolved through GraphQL. */
    public static function isGraphQl(Operation $operation): bool
    {
        return str_contains($operation::class, 'GraphQl');
    }
}
