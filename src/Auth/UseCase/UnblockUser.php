<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The exact inverse of BlockUser, for both callers. It needs no guard of its
 * own: an administrator cannot have blocked themselves, and unblocking an
 * account that is not blocked changes nothing.
 */
final readonly class UnblockUser
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private LoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(User $target): void
    {
        $target->unblock(new \DateTimeImmutable());
        $this->em->flush();
        $actor = $this->security->getUser();
        $this->auditLogger->info('user.unblock', [
            'action' => 'user.unblock',
            'actor_id' => $actor instanceof User ? (string) $actor->getId() : null,
            'target_id' => (string) $target->getId(),
        ]);
    }
}
