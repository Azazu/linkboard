<?php

declare(strict_types=1);

namespace App\Link\UseCase;

/**
 * What a caller knows about a link it wants created (design decision 2 of
 * add-web-ui). The rules document is already in its canonical form — the
 * caller validated it, because the two callers get it from different places:
 * the API from the raw JSON body, the UI from a form field.
 */
final readonly class NewLink
{
    /**
     * @param array<string, string>|null $utm
     * @param array<string, mixed>|null  $rules canonical form (RulesDocument::toArray())
     */
    public function __construct(
        public string $targetUrl,
        public ?string $slug = null,
        public ?array $utm = null,
        public ?\DateTimeImmutable $expiresAt = null,
        public ?int $maxClicks = null,
        public ?array $rules = null,
    ) {
    }
}
