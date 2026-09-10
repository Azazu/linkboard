<?php

declare(strict_types=1);

namespace App\Click;

/**
 * What routing resolved for one visit (§3.4 columns country, device_type, os,
 * browser, is_bot, resolved_by, variant). Built by the redirect from the
 * visitor profile and the resolution; ClickFacts::default() is the row of a
 * degraded or plain visit. Column fit is by construction: vocabularies ≤ 16,
 * browser cut to 32, variant names ≤ 16, resolved_by ≤ 8.
 */
final readonly class ClickFacts
{
    public function __construct(
        public ?string $country,
        public ?string $deviceType,
        public ?string $os,
        public ?string $browser,
        public bool $isBot,
        public string $resolvedBy,
        public ?string $variant,
    ) {
    }

    public static function default(): self
    {
        return new self(null, null, null, null, false, 'default', null);
    }
}
