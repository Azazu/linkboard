<?php

declare(strict_types=1);

namespace App\Auth\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Auth\Entity\User;
use App\Auth\UseCase\BlockUser;
use App\Auth\UseCase\CannotBlockOwnAccount;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The API's adapter over `BlockUser` (design decision 5 of
 * add-web-admin-and-stats): the action itself, its guard and its audit line
 * live in the use case, which the administrative page calls too. What is left
 * here is the API's rendering of the refusal.
 *
 * @implements ProcessorInterface<User, UserAdmin>
 */
final readonly class BlockUserProcessor implements ProcessorInterface
{
    public function __construct(private BlockUser $block)
    {
    }

    /**
     * @param User                 $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserAdmin
    {
        try {
            ($this->block)($data);
        } catch (CannotBlockOwnAccount $e) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($e->getMessage(), null, [], $data, 'id', (string) $data->getId())]));
        }

        return UserAdmin::fromUser($data);
    }
}
