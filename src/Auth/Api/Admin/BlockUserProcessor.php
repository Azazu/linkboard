<?php

declare(strict_types=1);

namespace App\Auth\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<User, UserAdmin>
 */
final readonly class BlockUserProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private LoggerInterface $auditLogger,
    ) {
    }

    /**
     * @param User                 $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserAdmin
    {
        $actor = $this->security->getUser();
        if ($actor instanceof User && $actor->getId()->equals($data->getId())) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('An admin cannot block their own account.', null, [], $data, 'id', (string) $data->getId())]));
        }

        $data->block(new \DateTimeImmutable());
        $this->em->flush();
        // After the flush: a failed write leaves no audit line (design applicability row 1).
        $this->auditLogger->info('user.block', [
            'action' => 'user.block',
            'actor_id' => $actor instanceof User ? (string) $actor->getId() : null,
            'target_id' => (string) $data->getId(),
        ]);

        return UserAdmin::fromUser($data);
    }
}
