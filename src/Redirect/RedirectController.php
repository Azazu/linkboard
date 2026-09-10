<?php

declare(strict_types=1);

namespace App\Redirect;

use App\Shared\Api\ProblemDetails;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * FR-RED-1…6: the public hot path. HTTP only — rate limit first (no SQL for a
 * flood), the request classified into a Visit (VisitFactory), the resolver's
 * decision into a response. Every response is `Cache-Control: no-store`; the 302 adds the
 * referrer policy; 4xx/5xx bodies are small pages or problem details by
 * Accept. The token is never read, so no session starts and no cookie is set.
 * Lowest priority: every application route wins over a slug.
 */
final readonly class RedirectController
{
    private const int UNAVAILABLE_RETRY_AFTER = 5;

    public function __construct(
        private RedirectRateLimit $rateLimit,
        private VisitFactory $visits,
        private RedirectResolver $resolver,
        private Environment $twig,
    ) {
    }

    #[Route('/{slug}', name: 'redirect', requirements: ['slug' => '[A-Za-z0-9_-]{3,32}'], methods: ['GET', 'HEAD'], priority: -100)]
    public function __invoke(Request $request, string $slug): Response
    {
        $verdict = $this->rateLimit->check($request->getClientIp() ?? 'unknown');
        if ($verdict->limited) {
            return $this->error($request, 429, 'Too Many Requests', 'Too many redirects from your address. Try again later.', 'redirect/rate_limited.html.twig', ['Retry-After' => (string) $verdict->retryAfter]);
        }

        $visit = $this->visits->fromRequest($request, new \DateTimeImmutable(), $request->isMethod('HEAD'));
        $decision = $this->resolver->resolve($slug, $visit);

        return match ($decision->status) {
            RedirectStatus::Redirect => $this->redirect((string) $decision->location),
            RedirectStatus::NotFound => $this->error($request, 404, 'Not Found', 'No such link.', 'redirect/not_found.html.twig'),
            RedirectStatus::Gone => $this->error($request, 410, 'Gone', 'This link has expired or reached its click limit.', 'redirect/gone.html.twig'),
            RedirectStatus::Unavailable => $this->error($request, 503, 'Service Unavailable', 'The link cannot be served right now. Try again shortly.', 'redirect/unavailable.html.twig', ['Retry-After' => (string) self::UNAVAILABLE_RETRY_AFTER]),
        };
    }

    private function redirect(string $location): Response
    {
        $response = new RedirectResponse($location, Response::HTTP_FOUND); // never 301: the target may change
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer-when-downgrade');

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function error(Request $request, int $status, string $title, string $detail, string $template, array $headers = []): Response
    {
        $response = str_contains((string) $request->headers->get('Accept'), 'application/json')
            ? ProblemDetails::response($status, $title, $detail)
            : new Response($this->twig->render($template, ['retry_after' => $headers['Retry-After'] ?? null]), $status);
        $response->headers->set('Cache-Control', 'no-store');
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
