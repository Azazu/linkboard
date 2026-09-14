<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Link\Entity\Link;
use App\Link\Validator\Slug;
use App\Link\Validator\TargetUrl;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the create and edit forms carry (design decision 8 of add-web-ui). Not
 * the API's CreateLinkInput: that DTO's rules constraint reads the raw HTTP
 * body, which a form post does not have. The constraints here are the API's
 * own — the same Slug and TargetUrl validators, the same lengths and bounds —
 * so a person and a client are told the same thing about the same value.
 */
final class LinkFormData
{
    #[Assert\NotBlank(message: 'A link needs a target URL.')]
    #[Assert\Length(max: Link::TARGET_URL_MAX_LENGTH)]
    #[TargetUrl]
    public string $targetUrl = '';

    /** Only offered when creating: the slug is immutable afterwards. */
    #[Slug]
    public ?string $slug = null;

    #[Assert\Length(max: 255)]
    public ?string $utmSource = null;
    #[Assert\Length(max: 255)]
    public ?string $utmMedium = null;
    #[Assert\Length(max: 255)]
    public ?string $utmCampaign = null;
    #[Assert\Length(max: 255)]
    public ?string $utmTerm = null;
    #[Assert\Length(max: 255)]
    public ?string $utmContent = null;

    #[Assert\GreaterThan('now', message: 'The expiry must be in the future.')]
    public ?\DateTimeImmutable $expiresAt = null;

    #[Assert\Positive(message: 'A click limit is at least 1.')]
    public ?int $maxClicks = null;

    public bool $isActive = true;

    #[Assert\Valid]
    public RulesFormData $rules;

    public function __construct()
    {
        $this->rules = new RulesFormData();
    }

    public static function fromLink(Link $link): self
    {
        $data = new self();
        $data->targetUrl = $link->getTargetUrl();
        // deliberately not the slug: it is immutable, the edit form does not
        // carry it, and the Slug constraint would find the link's own slug taken
        $utm = $link->getUtm() ?? [];
        $data->utmSource = $utm['utm_source'] ?? null;
        $data->utmMedium = $utm['utm_medium'] ?? null;
        $data->utmCampaign = $utm['utm_campaign'] ?? null;
        $data->utmTerm = $utm['utm_term'] ?? null;
        $data->utmContent = $utm['utm_content'] ?? null;
        // Doctrine hands back a +00:00 offset; the form's model timezone is named
        // UTC, and DateTimeType refuses a value whose zone merely means the same
        $data->expiresAt = $link->getExpiresAt()?->setTimezone(new \DateTimeZone('UTC'));
        $data->maxClicks = $link->getMaxClicks();
        $data->isActive = $link->isActive();
        $data->rules = RulesDocumentMapper::toForm($link->getRules());

        return $data;
    }

    /**
     * The UTM members as they are stored: only the ones that were filled in,
     * and null when none were — which clears them.
     *
     * @return array<string, string>|null
     */
    public function utm(): ?array
    {
        $utm = array_filter([
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
            'utm_term' => $this->utmTerm,
            'utm_content' => $this->utmContent,
        ], static fn (?string $value): bool => null !== $value && '' !== trim($value));

        return [] === $utm ? null : array_map(trim(...), $utm);
    }
}
