<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use App\Auth\Entity\ApiKey;
use Symfony\Component\Validator\Constraints as Assert;

/** Input of POST /api/v1/api-keys (FR-KEY-1). */
final class CreateApiKeyInput
{
    #[Assert\NotBlank(normalizer: 'trim', message: 'name must not be blank.')]
    #[Assert\Length(max: ApiKey::NAME_MAX_LENGTH, normalizer: 'trim', maxMessage: 'name must be at most {{ limit }} characters.')]
    public string $name = '';

    #[Assert\GreaterThan('now', message: 'expiresAt must be in the future.')]
    public ?\DateTimeImmutable $expiresAt = null;
}
