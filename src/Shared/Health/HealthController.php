<?php

declare(strict_types=1);

namespace App\Shared\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /health          liveness: the kernel answers, nothing else is touched
 * GET /health?deep=1   dependency probe (database, redis); 503 when any fails.
 *
 * Outside the API contour on purpose: not in the OpenAPI document, no
 * authentication, never cached. The deep probe is refused in prod until an
 * authorization boundary exists (users-and-security change).
 */
final class HealthController
{
    public function __construct(
        private readonly HealthProbe $probe,
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

        if ('prod' === $this->environment) {
            throw new NotFoundHttpException('The deep health probe is not exposed in this environment.');
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
