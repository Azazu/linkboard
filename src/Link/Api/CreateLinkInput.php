<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\ApiProperty;
use App\Link\Validator\Slug;
use App\Link\Validator\TargetUrl;
use App\Link\Validator\ValidRules;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateLinkInput
{
    #[Assert\Length(max: 2048)]
    #[TargetUrl]
    #[ApiProperty(example: 'https://example.com/products/spring?ref=newsletter')]
    public string $targetUrl = '';

    #[Slug]
    #[ApiProperty(example: 'spring-sale')]
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
    #[ApiProperty(example: ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'spring'])]
    public ?array $utm = null;

    /**
     * Routing-rules document. Placeholder: the serializer accepts any JSON here
     * and OpenAPI shows the field; validation and persistence read the raw
     * request body instead (RulesInput, design decision 2).
     */
    #[ValidRules]
    // `mixed` makes the generated schema `string|null`, which is not what
    // this field accepts: the document says object, like the resource's own
    #[ApiProperty(
        openapiContext: ['type' => 'object', 'nullable' => true],
        example: ['version' => 1, 'rules' => [['match' => ['country' => ['DE', 'AT']], 'target' => 'https://example.de/fruehling']]],
    )]
    public mixed $rules = null;

    #[Assert\GreaterThan('now', message: 'The expiry must be in the future.')]
    #[ApiProperty(example: '2026-12-31T23:59:59+00:00')]
    public ?\DateTimeImmutable $expiresAt = null;

    #[Assert\Positive(message: 'The click limit must be a positive integer.')]
    #[ApiProperty(example: 10000)]
    public ?int $maxClicks = null;
}
