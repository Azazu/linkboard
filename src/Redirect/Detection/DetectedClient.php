<?php

declare(strict_types=1);

namespace App\Redirect\Detection;

/**
 * Device detection mapped onto the specification's vocabularies (FR-RUL-3):
 * null means unrecognised; a bot has null dimensions and isBot true.
 */
final readonly class DetectedClient
{
    public function __construct(
        public ?string $deviceType,
        public ?string $os,
        public ?string $browser,
        public bool $isBot,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null, null, false);
    }
}
