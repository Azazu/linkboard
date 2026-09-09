<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Auth\Entity\User;
use App\Link\LinkRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * GET /api/v1/links — scoped to the authenticated user, never to a client-supplied owner.
 *
 * @implements ProviderInterface<LinkResource>
 */
final readonly class OwnLinksProvider implements ProviderInterface
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private Pagination $pagination,
        private Security $security,
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
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }
        $query = ListQueryFactory::fromRequest($context['request'] ?? null);
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);
        $items = array_map($this->publicUrl->toResource(...), $this->links->findPageForOwner($user->getId(), $query, $offset, $limit));

        return new TraversablePaginator(new \ArrayIterator($items), (float) $page, (float) $limit, (float) $this->links->countForOwner($user->getId(), $query));
    }
}
