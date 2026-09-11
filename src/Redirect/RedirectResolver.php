<?php

declare(strict_types=1);

namespace App\Redirect;

use App\Click\ClickFacts;
use App\Click\ClickRecorderInterface;
use App\Click\RecordOutcome;
use App\Click\Visit;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Redirect\Rules\Resolution;
use App\Redirect\Rules\RuleEvaluator;
use Psr\Log\LoggerInterface;

/**
 * The redirect use case. Order: lookup (failure → 503) → 404 for unknown or
 * inactive → 410 for expired or exhausted (fast path, no write) → routing
 * (profile → evaluate, inside one guard: hostile input or any exception →
 * the default target, one notice — FR-RUL-7) → HEAD answers from the link
 * state alone → record the click with its facts (the counter for a limited
 * link, then one message; a counter failure is the write failure below, a
 * dispatch failure is absorbed by the recorder) → 302 / 410 / the
 * write-failure policy. Log records carry ids, issue classes and exception
 * classes only — never the IP, user agent, header values, slug or target.
 */
final readonly class RedirectResolver
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private ClickRecorderInterface $recorder,
        private VisitorProfiler $profiler,
        private RuleEvaluator $evaluator,
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

        [$profile, $resolution] = $this->route($link, $visit);
        $location = UtmAppender::append($resolution->target, $link->getUtm() ?? []);
        if ($visit->isHead) {
            return RedirectDecision::redirect($location);
        }

        try {
            $outcome = $this->recorder->record($link, $visit, self::facts($profile, $resolution));
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

    /**
     * Routing inside one guard (design decision 5): hostile input is never
     * parsed; an exception in detection, geolocation or evaluation, or an
     * unusable stored document, degrades to the default target. One notice
     * either way, with the issue classes or the exception class.
     *
     * @return array{VisitorProfile, Resolution}
     */
    private function route(Link $link, Visit $visit): array
    {
        if ($visit->isHostile()) {
            $this->logger->notice('Routing degraded to the default target: hostile input', ['link_id' => (string) $link->getId(), 'issues' => $visit->inputIssues]);

            return [VisitorProfile::unknown(), Resolution::default($link->getTargetUrl())];
        }
        try {
            $profile = $this->profiler->profile($visit);

            return [$profile, $this->evaluator->evaluate($link->getRules(), $profile, $visit, $link->getId(), $link->getTargetUrl())];
        } catch (\Throwable $e) {
            $this->logger->notice('Routing degraded to the default target', ['link_id' => (string) $link->getId(), 'exception' => $e::class]);

            return [VisitorProfile::unknown(), Resolution::default($link->getTargetUrl())];
        }
    }

    private static function facts(VisitorProfile $profile, Resolution $resolution): ClickFacts
    {
        return new ClickFacts($profile->country, $profile->deviceType, $profile->os, $profile->browser, $profile->isBot, $resolution->resolvedBy->value, $resolution->variant);
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
