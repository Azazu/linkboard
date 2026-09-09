<?php

declare(strict_types=1);

namespace App\Link\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The public base of short URLs (APP_PUBLIC_URL, FR-LNK-11).
 */
final readonly class PublicUrl
{
    public function __construct(
        #[Autowire(env: 'APP_PUBLIC_URL')]
        public string $base,
    ) {
    }

    public function toResource(\App\Link\Entity\Link $link): LinkResource
    {
        return LinkResource::fromLink($link, $this->base);
    }
}
