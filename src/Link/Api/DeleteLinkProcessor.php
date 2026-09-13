<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Auth\Entity\User;
use App\Link\LinkRepositoryInterface;
use App\Link\UseCase\DeleteLink;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/v1/links/{id} — hard delete (FR-LNK-10). The HTTP half: resolve
 * the resource the provider produced back to the entity. The deletion, the
 * counter and cache cleanup and the audit line are
 * App\Link\UseCase\DeleteLink, which the web UI calls too (add-web-ui,
 * design decision 2).
 *
 * @implements ProcessorInterface<LinkResource, null>
 */
final readonly class DeleteLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private DeleteLink $deleteLink,
        private Security $security,
    ) {
    }

    /**
     * @param LinkResource         $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $link = $this->links->findById(Uuid::fromString($data->id)) ?? throw new NotFoundHttpException('No such link.');
        $actor = $this->security->getUser();

        ($this->deleteLink)($link, $actor instanceof User ? $actor : null);

        return null;
    }
}
