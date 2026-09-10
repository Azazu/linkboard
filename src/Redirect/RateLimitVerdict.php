<?php

declare(strict_types=1);

namespace App\Redirect;

final readonly class RateLimitVerdict
{
    private function __construct(
        public bool $limited,
        /** seconds until the next request may pass; set only when limited */
        public int $retryAfter = 0,
    ) {
    }

    public static function allowed(): self
    {
        return new self(false);
    }

    public static function limited(int $retryAfter): self
    {
        return new self(true, max(1, $retryAfter));
    }
}
