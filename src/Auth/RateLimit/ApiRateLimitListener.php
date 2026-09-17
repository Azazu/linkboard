<?php

declare(strict_types=1);

namespace App\Auth\RateLimit;

use App\Auth\ApiKey\ApiKeyTokenHandler;
use App\Auth\Entity\User;
use App\Shared\Api\ProblemDetails;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * FR-KEY-4 (design decision 6): one token per authenticated API request,
 * keyed by the API key or — for a JWT — by the user. A GraphQL request costs
 * one token per root selection instead, because one request there can ask for
 * as much as fifty REST calls would (change stretch-graphql, design decision
 * 3); `GraphQlCost` is the algorithm, and a document it cannot price is
 * refused here — before the executor, which is why that refusal keeps the
 * problem-details shape every other pre-controller refusal has. Hooked on the
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

    /**
     * @param list<string> $unlimitedPaths anchored patterns, without delimiters: the
     *                                     same values security.yaml and the OpenAPI
     *                                     decorator read (change polish-api-and-openapi)
     */
    public function __construct(
        #[Target('api_identity')]
        private RateLimiterFactoryInterface $limiter,
        private LoggerInterface $logger,
        #[Autowire('%app.api.unlimited_paths%')]
        private array $unlimitedPaths = [],
        /** @var list<string> anchored patterns of the GraphQL entrypoints, both of them */
        #[Autowire('%app.api.graphql_paths%')]
        private array $graphQlPaths = [],
    ) {
    }

    /** Whether the limiter counts a request for this path at all. */
    public function counts(string $path): bool
    {
        foreach ($this->unlimitedPaths as $pattern) {
            if (1 === preg_match('#'.$pattern.'#', $path)) {
                return false;
            }
        }

        return true;
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        if (self::FIREWALL !== $event->getFirewallName() || !$this->counts($request->getPathInfo())) {
            return;
        }
        $identity = self::identity($event);
        if (null === $identity) {
            return;
        }

        $cost = $this->costOf($request);
        if ($cost->isRefused()) {
            $event->setResponse(ProblemDetails::response(400, 'Bad Request', (string) $cost->refusal));

            return;
        }

        try {
            $limit = $this->limiter->create($identity)->consume($cost->tokens);
        } catch (\InvalidArgumentException $e) {
            // The limiter refuses to reserve more tokens than its own size —
            // a document asking for more reads than the whole budget holds.
            // That is a refusal, not an outage: failing open here would let
            // the largest documents through unlimited, which is the opposite
            // of what the budget is for (Gate 2 round 1, finding 3, found by
            // the test that finding asked for).
            $this->logger->info('GraphQL document asks for more than the whole budget', ['tokens' => $cost->tokens]);
            $response = ProblemDetails::response(429, 'Too Many Requests', 'The document asks for more reads than the rate limit allows in one window.');
            $response->headers->set('Retry-After', '60');
            // the same headers the other 429 carries, so a caller learns the
            // window it overran rather than only that it did: consume(0)
            // reports the state without reserving anything
            try {
                self::decorate($response->headers, $this->limiter->create($identity)->consume(0));
            } catch (\Throwable) {
                // the store is the only thing that can fail here, and a
                // missing header is not worth failing a refusal over
            }
            $event->setResponse($response);

            return;
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

    /**
     * What this request costs: one token for a REST call, one per root
     * selection for a GraphQL document.
     */
    private function costOf(Request $request): GraphQlCost
    {
        foreach ($this->graphQlPaths as $pattern) {
            if (1 === preg_match('#'.$pattern.'#', $request->getPathInfo())) {
                return GraphQlCost::of($request);
            }
        }

        return GraphQlCost::ofOneRequest();
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
