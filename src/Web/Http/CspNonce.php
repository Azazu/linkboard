<?php

declare(strict_types=1);

namespace App\Web\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The per-request nonce of the content security policy (design decision 6).
 *
 * It is generated once per main request and kept on the request's attributes,
 * so the template that renders the import map and the subscriber that writes
 * the policy read the same value, and nothing survives into the next request —
 * which a service property would, in a worker or a test kernel that is not
 * rebooted.
 */
final readonly class CspNonce
{
    public const string ATTRIBUTE = '_csp_nonce';

    public function __construct(private RequestStack $requests)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::ATTRIBUTE, base64_encode(random_bytes(16)));
        }
    }

    /** The nonce of the current request, or null outside one. */
    public function value(): ?string
    {
        $value = $this->requests->getCurrentRequest()?->attributes->get(self::ATTRIBUTE);

        return \is_string($value) ? $value : null;
    }
}
