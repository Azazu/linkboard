<?php

declare(strict_types=1);

namespace App\Auth\Api;

use ApiPlatform\GraphQl\Resolver\QueryItemResolverInterface;

/**
 * The `me` query, which has no identifier to resolve by.
 *
 * API Platform's item query reads by identifier, and `Me` is a singleton: the
 * REST operation ignores the one in its URI and answers with the authenticated
 * account. Exposed as an ordinary item query, GraphQL would therefore demand an
 * `id` argument that changes nothing — and invite a client to pass somebody
 * else's and wonder why its own account came back. A resolver lets the query
 * take no arguments; the answer still comes from `MeProvider`, so REST and
 * GraphQL cannot disagree about what `me` is (change stretch-graphql).
 */
final readonly class MeResolver implements QueryItemResolverInterface
{
    public function __construct(private MeProvider $provider)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function __invoke(?object $item, array $context): object
    {
        $operation = $context['operation'] ?? null;

        return $this->provider->provide(
            $operation instanceof \ApiPlatform\Metadata\Operation
                ? $operation
                : new \ApiPlatform\Metadata\GraphQl\Query(class: Me::class),
        );
    }
}
