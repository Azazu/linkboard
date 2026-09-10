<?php

declare(strict_types=1);

namespace App\Redirect;

/**
 * Outcome of resolving a slug (design decision 2); the controller maps it to
 * HTTP. `location` is set only for a redirect.
 */
final readonly class RedirectDecision
{
    private function __construct(
        public RedirectStatus $status,
        public ?string $location = null,
    ) {
    }

    public static function notFound(): self
    {
        return new self(RedirectStatus::NotFound);
    }

    public static function gone(): self
    {
        return new self(RedirectStatus::Gone);
    }

    public static function unavailable(): self
    {
        return new self(RedirectStatus::Unavailable);
    }

    public static function redirect(string $location): self
    {
        return new self(RedirectStatus::Redirect, $location);
    }
}
