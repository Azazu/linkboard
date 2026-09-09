<?php

declare(strict_types=1);

namespace App\Auth\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Loads the target account of a block/unblock operation; 404 when the id
 * is not a UUID or matches nothing. Returns the entity: the processors
 * mutate it and answer with UserAdmin.
 *
 * @implements ProviderInterface<User>
 */
final readonly class UserAdminItemProvider implements ProviderInterface
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): User
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('No such account.');
        }

        return $this->users->findById(Uuid::fromString($id)) ?? throw new NotFoundHttpException('No such account.');
    }
}
