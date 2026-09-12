<?php

declare(strict_types=1);

namespace App\Shared\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /health          liveness: the kernel answers, nothing else is touched
 * GET /health?deep=1   dependency probe (database, redis); 503 when any fails.
 *
 * Outside the API contour on purpose: not in the OpenAPI document, no
 * firewall, never cached. In prod the deep probe runs only for a request the
 * ProbeAuthorizer verifies (a valid admin API key, spec health-check); every
 * other request gets one and the same 404 problem body.
 */
final class HealthController
{
    public function __construct(
        private readonly HealthProbe $probe,
        private readonly ProbeAuthorizer $authorizer,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$request->query->getBoolean('deep')) {
            return $this->json(['status' => 'ok'], 200);
        }

        if ('prod' === $this->environment && !$this->authorizer->authorize($request->headers->get('Authorization'))) {
            // Explicit RFC 9457 body, identical for every refusal (design decision 6):
            // /health is outside the API contour, so no framework error renderer is
            // guaranteed to produce problem+json here.
            $response = $this->json([
                'type' => '/errors/404',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'The deep health probe is not exposed in this environment.',
            ], 404);
            $response->headers->set('Content-Type', 'application/problem+json');

            return $response;
        }

        $report = $this->probe->run();

        return $this->json($report->toArray(), $report->httpStatus());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
