<?php

declare(strict_types=1);

namespace App\Auth\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Auth\Entity\User;
use App\Auth\UseCase\UnblockUser;

/**
 * The API's adapter over `UnblockUser`; see `BlockUserProcessor`.
 *
 * @implements ProcessorInterface<User, UserAdmin>
 */
final readonly class UnblockUserProcessor implements ProcessorInterface
{
    public function __construct(private UnblockUser $unblock)
    {
    }

    /**
     * @param User                 $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserAdmin
    {
        ($this->unblock)($data);

        return UserAdmin::fromUser($data);
    }
}
