<?php

declare(strict_types=1);

namespace App\Redirect\Rules;

final readonly class Resolution
{
    public function __construct(
        public string $target,
        public ResolvedBy $resolvedBy,
        public ?string $variant = null,
    ) {
    }

    public static function default(string $targetUrl): self
    {
        return new self($targetUrl, ResolvedBy::Default);
    }
}
