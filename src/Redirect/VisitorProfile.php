<?php

declare(strict_types=1);

namespace App\Redirect;

/**
 * The resolved dimensions of one visitor (FR-RUL-3): null = unknown, which
 * skips every rule naming that dimension (FR-RUL-4).
 */
final readonly class VisitorProfile
{
    public function __construct(
        public ?string $deviceType,
        public ?string $os,
        public ?string $browser,
        public bool $isBot,
        public ?string $country,
        public ?string $language,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null, null, false, null, null);
    }
}
