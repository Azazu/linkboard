<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Auth\ApiKeyRepositoryInterface;
use App\Auth\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * GET /api/v1/api-keys — the caller's keys, newest first (spec api-keys "List and revoke own keys").
 *
 * @implements ProviderInterface<ApiKeyOutput>
 */
final readonly class OwnApiKeysProvider implements ProviderInterface
{
    public function __construct(
        private ApiKeyRepositoryInterface $keys,
        private Pagination $pagination,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return TraversablePaginator<ApiKeyOutput>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);
        $items = array_map(ApiKeyOutput::fromKey(...), $this->keys->listByOwner($user, $offset, $limit));

        return new TraversablePaginator(new \ArrayIterator($items), (float) $page, (float) $limit, (float) $this->keys->countByOwner($user));
    }
}
