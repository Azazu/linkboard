<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Link\LinkRepositoryInterface;

/**
 * GET /api/v1/admin/links — every user's links (ROLE_ADMIN via operation security).
 *
 * @implements ProviderInterface<LinkResource>
 */
final readonly class AllLinksProvider implements ProviderInterface
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private Pagination $pagination,
        private PublicUrl $publicUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return TraversablePaginator<LinkResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $query = ListQueryFactory::fromRequest($context['request'] ?? null);
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);
        $items = array_map($this->publicUrl->toResource(...), $this->links->findPage($query, $offset, $limit));

        return new TraversablePaginator(new \ArrayIterator($items), (float) $page, (float) $limit, (float) $this->links->count($query));
    }
}
