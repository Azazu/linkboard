<?php

declare(strict_types=1);

namespace App\Redirect;

use App\Click\ClickRecorderInterface;
use App\Click\RecordOutcome;
use App\Click\Visit;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The redirect use case (design decision 2). Order: lookup (failure → 503,
 * decision 13) → 404 for unknown or inactive → 410 for expired or exhausted
 * (fast path, no write) → HEAD answers from the link state alone → record the
 * click → 302 / 410 / the write-failure policy of decision 5. Log records
 * carry ids and exception classes only — never the IP, user agent, slug value
 * or target URL.
 */
final readonly class RedirectResolver
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private ClickRecorderInterface $recorder,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $slug, Visit $visit): RedirectDecision
    {
        try {
            $link = $this->links->findBySlug($slug);
        } catch (\Throwable $e) {
            $this->logger->error('Link lookup failed; redirect unavailable', ['slug_length' => \strlen($slug), 'exception' => $e::class]);

            return RedirectDecision::unavailable();
        }
        if (null === $link || !$link->isActive()) {
            return RedirectDecision::notFound();
        }
        if (self::isExpired($link, $visit->occurredAt) || self::isExhausted($link)) {
            return RedirectDecision::gone();
        }

        $location = UtmAppender::append($link->getTargetUrl(), $link->getUtm() ?? []);
        if ($visit->isHead) {
            return RedirectDecision::redirect($location);
        }

        try {
            $outcome = $this->recorder->record($link, $visit);
        } catch (\Throwable $e) {
            if (null === $link->getMaxClicks()) {
                // the one case in which a 302 leaves no click (spec redirect, "Failures of the stores")
                $this->logger->error('Click not recorded; redirect served anyway', ['link_id' => (string) $link->getId(), 'exception' => $e::class]);

                return RedirectDecision::redirect($location);
            }
            $this->logger->warning('Click limit could not be enforced; redirect unavailable', ['link_id' => (string) $link->getId(), 'exception' => $e::class]);

            return RedirectDecision::unavailable();
        }

        return RecordOutcome::Allowed === $outcome ? RedirectDecision::redirect($location) : RedirectDecision::gone();
    }

    private static function isExpired(Link $link, \DateTimeImmutable $now): bool
    {
        $expiresAt = $link->getExpiresAt();

        return null !== $expiresAt && $expiresAt <= $now;
    }

    private static function isExhausted(Link $link): bool
    {
        $max = $link->getMaxClicks();

        return null !== $max && $link->getClickCount() >= $max;
    }
}
