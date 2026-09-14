<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Blocking an account, for both callers (design decision 5 of
 * add-web-admin-and-stats): the API's state processor and the administrative
 * page. An administrator may not block their own account — the rule is here
 * rather than in a caller, so neither entry point can miss it.
 *
 * The audit line is written after the flush: a write that fails leaves no line
 * claiming something that did not happen. The converse — a crash between the
 * flush and the line — leaves a blocked account unaudited, which is the lesser
 * of the two and is stated in the design's applicability table.
 *
 * Blocking an already blocked account changes nothing observable and answers
 * the same, so a resubmitted confirmation is safe.
 */
final readonly class BlockUser
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private LoggerInterface $auditLogger,
    ) {
    }

    /**
     * @throws CannotBlockOwnAccount when the actor is the target
     */
    public function __invoke(User $target): void
    {
        $actor = $this->security->getUser();
        if ($actor instanceof User && $actor->getId()->equals($target->getId())) {
            throw new CannotBlockOwnAccount();
        }

        $target->block(new \DateTimeImmutable());
        $this->em->flush();
        $this->auditLogger->info('user.block', [
            'action' => 'user.block',
            'actor_id' => $actor instanceof User ? (string) $actor->getId() : null,
            'target_id' => (string) $target->getId(),
        ]);
    }
}
