<?php

declare(strict_types=1);

namespace App\Auth\Registration;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Spec user-accounts, "Self-registration with email and password": one switch
 * governs both entry points (change stretch-public-hosting, design decision
 * 5).
 *
 * Registration has two of them — the Twig controller at `/register` and the
 * API Platform operation at `/api/v1/auth/register` — and the capability
 * requires BOTH to answer 404 when the switch is off. A check in each is the
 * shape of the bug: somebody adds a third, or changes one and not the other,
 * and the switch silently half-works. So one listener refuses both, reading
 * the paths from a parameter in `config/services.yaml` beside the API path
 * rules that are already there for this reason.
 *
 * It throws the framework's not-found exception rather than building a
 * response, so each surface is rendered by the error handling it already has:
 * problem details under `/api`, the ordinary 404 page elsewhere. No second
 * rendering to keep in step with the first.
 *
 * Priority 16: after the router (32), so an API request already carries the
 * attributes its error rendering needs, and before the firewall (8), because a
 * surface that does not exist does not ask who you are.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final readonly class ClosedRegistration
{
    /**
     * @param list<string> $paths anchored patterns without delimiters, as every other path rule in this repository
     */
    public function __construct(
        #[Autowire(env: 'bool:REGISTRATION_ENABLED')]
        private bool $enabled,
        #[Autowire('%app.registration_paths%')]
        private array $paths = [],
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->enabled) {
            return;
        }

        if ($this->isRegistration($event->getRequest()->getPathInfo())) {
            throw new NotFoundHttpException('No such endpoint.');
        }
    }

    public function isRegistration(string $path): bool
    {
        foreach ($this->paths as $pattern) {
            if (1 === preg_match('#'.$pattern.'#', $path)) {
                return true;
            }
        }

        return false;
    }
}
