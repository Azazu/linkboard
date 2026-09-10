<?php

declare(strict_types=1);

namespace App\Shared\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RFC 9457 problem details for responses produced outside API Platform
 * (spec api-error-format): the JWT and rate-limit paths of the API and the
 * public redirect when the client asks for JSON.
 */
final class ProblemDetails
{
    public static function response(int $status, string $title, string $detail): JsonResponse
    {
        $response = new JsonResponse([
            'type' => '/errors/'.$status,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $status);
        $response->headers->set('Content-Type', 'application/problem+json');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function __construct()
    {
    }
}
