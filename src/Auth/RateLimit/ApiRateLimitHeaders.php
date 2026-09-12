<?php

declare(strict_types=1);

namespace App\Auth\RateLimit;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Adds X-RateLimit-Limit and X-RateLimit-Remaining to every response of a
 * request the API limiter accepted (FR-KEY-4) — 2xx, the access listener's
 * 403, 404 and 422 alike. Unauthenticated requests never carry the stash.
 */
#[AsEventListener(event: ResponseEvent::class)]
final class ApiRateLimitHeaders
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $limit = ApiRateLimitListener::limitOf($event->getRequest());
        if (null !== $limit) {
            ApiRateLimitListener::decorate($event->getResponse()->headers, $limit);
        }
    }
}
