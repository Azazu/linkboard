<?php

declare(strict_types=1);

namespace App\Web\Http;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `csp_nonce()` for the layout, which passes it to `importmap()`: the import
 * map and the entry-point module are the only inline scripts of the page, and
 * the policy admits them by nonce rather than by `unsafe-inline`.
 */
final class CspTwigExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonce $nonce)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', $this->nonce->value(...))];
    }
}
