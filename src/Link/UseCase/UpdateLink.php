<?php

declare(strict_types=1);

namespace App\Link\UseCase;

use App\Analytics\Cache\ReportCache;
use App\Auth\Entity\User;
use App\Link\Entity\Link;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Changing a link, for both callers (design decision 2 of add-web-ui). Only
 * the fields the caller named change; the link's cached reports are
 * invalidated after the flush (best effort — capability analytics), and an
 * action on somebody else's link is audited after the flush, so a failed write
 * leaves no audit line.
 */
final readonly class UpdateLink
{
    public function __construct(
        private EntityManagerInterface $em,
        private ReportCache $reportCache,
        private ClockInterface $clock,
        private LoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(Link $link, LinkChanges $changes, ?User $actor): void
    {
        $now = $this->clock->now();
        $wasActive = $link->isActive();

        if ($changes->names('targetUrl')) {
            $link->changeTarget($changes->targetUrl(), $now);
        }
        if ($changes->names('utm')) {
            $link->replaceUtm($changes->utm(), $now);
        }
        if ($changes->names('expiresAt')) {
            $link->setExpiry($changes->expiresAt(), $now);
        }
        if ($changes->names('maxClicks')) {
            $link->setClickLimit($changes->maxClicks(), $now);
        }
        if ($changes->names('rules')) {
            $link->replaceRules($changes->rules(), $now);
        }
        if ($changes->names('isActive')) {
            $changes->active() ? $link->activate($now) : $link->deactivate($now);
        }

        $this->em->flush();
        $this->reportCache->forgetLink($link->getId());

        if (null === $actor || $actor->getId()->equals($link->getOwner()->getId())) {
            return;
        }
        $action = match (true) {
            $wasActive && !$link->isActive() => 'link.deactivate',
            !$wasActive && $link->isActive() => 'link.activate',
            default => 'link.update',
        };
        $this->auditLogger->info($action, [
            'action' => $action,
            'actor_id' => (string) $actor->getId(),
            'target_id' => (string) $link->getId(),
            'owner_id' => (string) $link->getOwner()->getId(),
        ]);
    }
}
