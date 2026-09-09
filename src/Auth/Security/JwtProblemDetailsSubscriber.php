<?php

declare(strict_types=1);

namespace App\Auth\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTFailureEventInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * Keeps the API's one error format on the JWT path (spec authentication,
 * api-error-format): every Lexik failure becomes RFC 9457 problem details —
 * 401 for missing/invalid/expired tokens and bad credentials, 403 with
 * detail "blocked" for a blocked account (BlockedUserChecker) — and the
 * success payload gains `expiresAt` next to `token`.
 */
final readonly class JwtProblemDetailsSubscriber implements EventSubscriberInterface
{
    public function __construct(private JWTTokenManagerInterface $jwtManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::AUTHENTICATION_SUCCESS => 'onSuccess',
            Events::AUTHENTICATION_FAILURE => 'onFailure',
            Events::JWT_NOT_FOUND => 'onTokenProblem',
            Events::JWT_INVALID => 'onTokenProblem',
            Events::JWT_EXPIRED => 'onTokenProblem',
        ];
    }

    public function onSuccess(AuthenticationSuccessEvent $event): void
    {
        $data = $event->getData();
        if (!isset($data['token']) || !\is_string($data['token'])) {
            return;
        }
        $payload = $this->jwtManager->parse($data['token']);
        if (isset($payload['exp']) && \is_int($payload['exp'])) {
            $data['expiresAt'] = (new \DateTimeImmutable('@'.$payload['exp']))->format(\DateTimeInterface::RFC3339);
        }
        $event->setData($data);
    }

    public function onFailure(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();
        if ($exception instanceof AccountStatusException) {
            $event->setResponse(self::problem(403, 'Forbidden', BlockedUserChecker::MESSAGE));

            return;
        }
        // Never say whether the email exists (spec authentication, "Invalid credentials").
        $event->setResponse(self::problem(401, 'Unauthorized', 'Invalid credentials.'));
    }

    public function onTokenProblem(JWTFailureEventInterface $event): void
    {
        // A blocked account presenting a still-valid token: the user checker's
        // AccountStatusException arrives wrapped in Lexik's JWT_INVALID event.
        $exception = $event->getException();
        for ($e = $exception; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof AccountStatusException) {
                $event->setResponse(self::problem(403, 'Forbidden', BlockedUserChecker::MESSAGE));

                return;
            }
        }

        $detail = match (true) {
            $event instanceof JWTExpiredEvent => 'Expired token.',
            $event instanceof JWTNotFoundEvent => 'Missing bearer token.',
            $event instanceof JWTInvalidEvent => 'Invalid token.',
            default => 'Authentication failed.',
        };
        $event->setResponse(self::problem(401, 'Unauthorized', $detail));
    }

    public static function problem(int $status, string $title, string $detail): JsonResponse
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
}
