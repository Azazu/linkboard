<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use App\Auth\Entity\ApiKey;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input of POST /api/v1/api-keys (FR-KEY-1). `expiresAt` stays a string here
 * so its RFC 3339 syntax and calendar validity are checked on the value the
 * client sent — the serializer's DateTime fallback would otherwise accept
 * relative text such as "tomorrow" (Gate 2 round 1, finding 1). The processor
 * parses it and checks that it lies in the future against the clock.
 */
final class CreateApiKeyInput
{
    public const string EXPIRES_AT_FORMAT = \DateTimeInterface::RFC3339;

    #[Assert\NotBlank(normalizer: 'trim', message: 'name must not be blank.')]
    #[Assert\Length(max: ApiKey::NAME_MAX_LENGTH, normalizer: 'trim', maxMessage: 'name must be at most {{ limit }} characters.')]
    public string $name = '';

    #[Assert\DateTime(format: self::EXPIRES_AT_FORMAT, message: 'expiresAt must be an RFC 3339 timestamp, for example 2027-01-01T00:00:00Z.')]
    public ?string $expiresAt = null;
}
