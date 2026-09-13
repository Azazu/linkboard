<?php

declare(strict_types=1);

namespace App\Web\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * NFR-SEC-5, design decision 6. `X-Content-Type-Options` goes on every
 * response; the document headers and the content security policy go on the
 * HTML pages, which are the only responses a browser renders.
 *
 * The policy is not sent under `^/api(/|$)`: API Platform's Swagger UI at
 * /api/docs bootstraps with an inline script this application does not
 * control, and admitting `unsafe-inline` for everyone to accommodate it would
 * cost more than the one page is worth. The segment boundary matters — `^/api`
 * alone would also exempt /api-keys, the one page that renders a secret.
 * Excluded pages keep the other three headers.
 */
final readonly class SecurityHeadersSubscriber
{
    private const string POLICY = "default-src 'self'; script-src 'self' 'nonce-%s'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
    private const string API_PATH = '#^/api(/|$)#';

    public function __construct(private CspNonce $nonce)
    {
    }

    // after the framework's ResponseListener (priority 0), which is what calls
    // Response::prepare() and therefore what settles the Content-Type this
    // listener reads to tell a rendered page from an API payload
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -128)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $response = $event->getResponse();
        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');

        if (!str_starts_with((string) $headers->get('Content-Type', ''), 'text/html')) {
            return;
        }
        $headers->set('X-Frame-Options', 'DENY');
        if (!$headers->has('Referrer-Policy')) {
            // the redirect sets its own, deliberately weaker one (spec redirect)
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        if (1 === preg_match(self::API_PATH, $event->getRequest()->getPathInfo())) {
            return;
        }
        $nonce = $this->nonce->value();
        if (null !== $nonce) {
            $headers->set('Content-Security-Policy', \sprintf(self::POLICY, $nonce));
        }
    }
}
