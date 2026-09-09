<?php

declare(strict_types=1);

namespace App\Link\Api;

use App\Link\Validator\TargetUrl;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Merge-patch body. Presence is read from the decoded request body by the
 * processor (design decision 6); constraints here validate present values.
 * Null handling per field is the processor's job.
 */
final class UpdateLinkInput
{
    #[Assert\Length(max: 2048)]
    #[TargetUrl]
    public ?string $targetUrl = null;

    /** Only compared with the current slug: the slug is immutable (FR-LNK-4). */
    public ?string $slug = null;

    /** @var array<string, string>|null */
    #[Assert\Type('array')]
    #[Assert\Collection(fields: [
        'utm_source' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
        'utm_medium' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
        'utm_campaign' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
        'utm_term' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
        'utm_content' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
    ], allowExtraFields: false, extraFieldsMessage: 'Only utm_source, utm_medium, utm_campaign, utm_term and utm_content are allowed.')]
    public ?array $utm = null;

    #[Assert\GreaterThan('now', message: 'The expiry must be in the future.')]
    public ?\DateTimeImmutable $expiresAt = null;

    #[Assert\Positive(message: 'The click limit must be a positive integer.')]
    public ?int $maxClicks = null;

    public ?bool $isActive = null;
}
