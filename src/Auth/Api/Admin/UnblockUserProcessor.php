<?php

declare(strict_types=1);

namespace App\Auth\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<User, UserAdmin>
 */
final readonly class UnblockUserProcessor implements ProcessorInterface
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
        $data->unblock(new \DateTimeImmutable());
        $this->em->flush();
        $actor = $this->security->getUser();
        $this->auditLogger->info('user.unblock', [
            'action' => 'user.unblock',
            'actor_id' => $actor instanceof User ? (string) $actor->getId() : null,
            'target_id' => (string) $data->getId(),
        ]);

        return UserAdmin::fromUser($data);
    }
}
