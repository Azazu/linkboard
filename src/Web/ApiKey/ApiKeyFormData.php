<?php

declare(strict_types=1);

namespace App\Web\ApiKey;

use App\Auth\Entity\ApiKey;
use Symfony\Component\Validator\Constraints as Assert;

/** The create-a-key form. The rules are the API's (spec api-keys). */
final class ApiKeyFormData
{
    #[Assert\NotBlank(normalizer: 'trim', message: 'A key needs a name.')]
    #[Assert\Length(max: ApiKey::NAME_MAX_LENGTH, normalizer: 'trim')]
    public string $name = '';

    #[Assert\GreaterThan('now', message: 'The expiry must be in the future.')]
    public ?\DateTimeImmutable $expiresAt = null;
}
