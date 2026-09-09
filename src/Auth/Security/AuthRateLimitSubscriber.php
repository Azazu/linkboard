<?php

declare(strict_types=1);

namespace App\Auth\Security;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;

/**
 * FR-AUTH-4: at most N requests per minute per client IP on the endpoints
 * that take credentials. Runs before the firewall (priority 16 > 8) so a
 * flood never reaches password hashing. The client IP is the one Symfony
 * derives through the trusted-proxy configuration (FR-KEY-5).
 */
final readonly class AuthRateLimitSubscriber implements EventSubscriberInterface
{
    /** @var list<array{method: string, pattern: string}> */
    private const array GUARDED = [
        ['method' => 'POST', 'pattern' => '#^/login$#'],
        ['method' => 'POST', 'pattern' => '#^/register$#'],
        ['method' => 'POST', 'pattern' => '#^/api/v1/auth/#'],
    ];

    public function __construct(
        #[Target('auth_ip')]
        private RateLimiterFactoryInterface $authIpLimiter,
        private Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!self::isGuarded($request)) {
            return;
        }

        $limit = $this->authIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        $event->setResponse(self::isApi($request)
            ? $this->problem($retryAfter)
            : $this->page($retryAfter));
    }

    public static function isGuarded(Request $request): bool
    {
        foreach (self::GUARDED as $rule) {
            if ($request->isMethod($rule['method']) && 1 === preg_match($rule['pattern'], $request->getPathInfo())) {
                return true;
            }
        }

        return false;
    }

    private static function isApi(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/');
    }

    private function problem(int $retryAfter): Response
    {
        $response = JwtProblemDetailsSubscriber::problem(429, 'Too Many Requests', 'Too many authentication attempts. Try again later.');
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    private function page(int $retryAfter): Response
    {
        return new Response(
            $this->twig->render('security/rate_limited.html.twig', ['retry_after' => $retryAfter]),
            429,
            ['Retry-After' => (string) $retryAfter, 'Cache-Control' => 'no-store'],
        );
    }
}
