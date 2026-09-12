<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Auth\ApiKeyRepositoryInterface;
use App\Auth\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Loads one of the caller's keys by id — 404 for a malformed id, an unknown
 * id and another user's key alike (design decision 5: other users' keys are
 * invisible, so the item operation is no oracle).
 *
 * @implements ProviderInterface<ApiKeyOutput>
 */
final readonly class OwnApiKeyItemProvider implements ProviderInterface
{
    public function __construct(private ApiKeyRepositoryInterface $keys, private Security $security)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ApiKeyOutput
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('No such API key.');
        }
        $key = $this->keys->findByIdAndOwner(Uuid::fromString($id), $user) ?? throw new NotFoundHttpException('No such API key.');

        return ApiKeyOutput::fromKey($key);
    }
}
