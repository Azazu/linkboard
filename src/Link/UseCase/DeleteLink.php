<?php

declare(strict_types=1);

namespace App\Link\UseCase;

use App\Analytics\Cache\ReportCache;
use App\Auth\Entity\User;
use App\Click\Counter\ClickCounterInterface;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Deleting a link, for both callers (design decision 2 of add-web-ui).
 * Dependents cascade through the FK contract; the Redis click counter is
 * removed best effort after the flush (a counter failure is logged, never
 * fails the deletion); the link's cached reports and the global statistics are
 * invalidated (best effort); queued click messages are discarded by their
 * handler. An action on somebody else's link is audited after the flush.
 */
final readonly class DeleteLink
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private EntityManagerInterface $em,
        private ClickCounterInterface $counter,
        private ReportCache $reportCache,
        private LoggerInterface $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(Link $link, ?User $actor): void
    {
        $ownerId = (string) $link->getOwner()->getId();
        $linkId = $link->getId();

        $this->links->remove($link);
        $this->em->flush();

        try {
            $this->counter->forget($linkId);
        } catch (\Throwable $e) {
            $this->logger->warning('Click counter key not removed with the link', ['link_id' => (string) $linkId, 'exception' => $e::class]);
        }
        $this->reportCache->forgetLink($linkId);
        $this->reportCache->forgetGlobal($linkId);

        if (null === $actor || (string) $actor->getId() === $ownerId) {
            return;
        }
        $this->auditLogger->info('link.delete', [
            'action' => 'link.delete',
            'actor_id' => (string) $actor->getId(),
            'target_id' => (string) $linkId,
            'owner_id' => $ownerId,
        ]);
    }
}
