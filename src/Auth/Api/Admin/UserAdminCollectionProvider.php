<?php

declare(strict_types=1);

namespace App\Auth\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Auth\UserRepositoryInterface;

/**
 * @implements ProviderInterface<UserAdmin>
 */
final readonly class UserAdminCollectionProvider implements ProviderInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private Pagination $pagination,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return TraversablePaginator<UserAdmin>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);
        $items = array_map(UserAdmin::fromUser(...), $this->users->findPage($offset, $limit));

        return new TraversablePaginator(new \ArrayIterator($items), (float) $page, (float) $limit, (float) $this->users->count());
    }
}
