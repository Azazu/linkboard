<?php

declare(strict_types=1);

namespace App\Auth\ApiKey;

use App\Auth\Security\BlockedUserChecker;
use App\Shared\Api\ProblemDetails;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * The API's error shape for a refused key (design decision 4): 403 `blocked`
 * for a blocked owner (the user checker's AccountStatusException), 401 with a
 * constant detail for everything else — unknown, revoked and expired keys are
 * indistinguishable to the client.
 */
final class ApiKeyAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof AccountStatusException) {
            return ProblemDetails::response(403, 'Forbidden', BlockedUserChecker::MESSAGE);
        }

        return ProblemDetails::response(401, 'Unauthorized', 'Invalid API key.');
    }
}
