<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Link\LinkRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the link of an item operation as a LinkResource (the voter's subject).
 * 404 for a non-UUID or unknown id — before any authorization check, so an
 * unknown id looks the same to everyone.
 *
 * @implements ProviderInterface<LinkResource>
 */
final readonly class LinkItemProvider implements ProviderInterface
{
    public function __construct(private LinkRepositoryInterface $links, private PublicUrl $publicUrl)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): LinkResource
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('No such link.');
        }
        $link = $this->links->findById(Uuid::fromString($id)) ?? throw new NotFoundHttpException('No such link.');

        return $this->publicUrl->toResource($link);
    }
}
