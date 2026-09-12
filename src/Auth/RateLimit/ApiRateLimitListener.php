<?php

declare(strict_types=1);

namespace App\Auth\RateLimit;

use App\Auth\ApiKey\ApiKeyTokenHandler;
use App\Auth\Entity\User;
use App\Shared\Api\ProblemDetails;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * FR-KEY-4 (design decision 6): one token per authenticated API request,
 * keyed by the API key or — for a JWT — by the user. Hooked on the
 * authentication success of the stateless `api` firewall, which happens once
 * per request and BEFORE access control, so a request the access listener
 * later refuses with 403 is counted like any other. Over the limit the
 * response is set here and the authenticator returns it: nothing further
 * runs. Fail-open: a limiter store failure is a warning, never a refused
 * request. The accepted RateLimit is stashed on the request for
 * ApiRateLimitHeaders.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final readonly class ApiRateLimitListener
{
    public const string FIREWALL = 'api';
    public const string REQUEST_ATTRIBUTE = '_api_rate_limit';
    private const string UNLIMITED_PATH = '#^/api/v1/auth/#';

    public function __construct(
        #[Target('api_identity')]
        private RateLimiterFactoryInterface $limiter,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        if (self::FIREWALL !== $event->getFirewallName() || 1 === preg_match(self::UNLIMITED_PATH, $request->getPathInfo())) {
            return;
        }
        $identity = self::identity($event);
        if (null === $identity) {
            return;
        }

        try {
            $limit = $this->limiter->create($identity)->consume();
        } catch (\Throwable $e) {
            $this->logger->warning('API rate limiter unavailable; failing open', ['exception' => $e::class]);

            return;
        }

        if ($limit->isAccepted()) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $limit);

            return;
        }

        $response = ProblemDetails::response(429, 'Too Many Requests', 'API rate limit exceeded. Try again later.');
        $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        self::decorate($response->headers, $limit);
        $event->setResponse($response);
    }

    /** `key:<id>` for a key-authenticated request (badge attribute), `user:<id>` for a JWT. */
    private static function identity(LoginSuccessEvent $event): ?string
    {
        $badge = $event->getPassport()->getBadge(UserBadge::class);
        $keyId = $badge instanceof UserBadge ? ($badge->getAttributes()[ApiKeyTokenHandler::BADGE_ATTRIBUTE] ?? null) : null;
        if (\is_string($keyId)) {
            return 'key:'.$keyId;
        }
        $user = $event->getUser();

        return $user instanceof User ? 'user:'.$user->getId()->toRfc4122() : null;
    }

    public static function decorate(\Symfony\Component\HttpFoundation\ResponseHeaderBag $headers, RateLimit $limit): void
    {
        $headers->set('X-RateLimit-Limit', (string) $limit->getLimit());
        $headers->set('X-RateLimit-Remaining', (string) max(0, $limit->getRemainingTokens()));
    }

    public static function limitOf(Request $request): ?RateLimit
    {
        $limit = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $limit instanceof RateLimit ? $limit : null;
    }
}
