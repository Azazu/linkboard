<?php

declare(strict_types=1);

namespace App\Redirect;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * FR-RED-6 with the fail-open policy of design decision 9. The `redirect_ip`
 * limiter is the only Redis use on the hot path (storage and lock); the
 * installed sliding-window limiter acquires the lock and touches storage
 * inside consume(), so the whole create()->consume() expression is guarded:
 * when it fails the request proceeds as allowed and a warning names the
 * failure class — never the client IP. The limit holds exactly while Redis
 * is reachable; redirect availability never depends on it.
 */
final readonly class RedirectRateLimit
{
    public function __construct(
        #[Target('redirect_ip')]
        private RateLimiterFactoryInterface $limiter,
        private LoggerInterface $logger,
    ) {
    }

    public function check(string $clientIp): RateLimitVerdict
    {
        try {
            $limit = $this->limiter->create($clientIp)->consume();
        } catch (\Throwable $e) {
            $this->logger->warning('Redirect rate limiter unavailable; failing open', ['exception' => $e::class]);

            return RateLimitVerdict::allowed();
        }
        if ($limit->isAccepted()) {
            return RateLimitVerdict::allowed();
        }

        return RateLimitVerdict::limited($limit->getRetryAfter()->getTimestamp() - time());
    }
}
