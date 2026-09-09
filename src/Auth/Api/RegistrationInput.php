<?php

declare(strict_types=1);

namespace App\Auth\Api;

use App\Auth\Validator\UniqueEmail;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input of POST /api/v1/auth/register (specification FR-AUTH-1).
 */
final class RegistrationInput
{
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5)]
    #[Assert\Length(max: 180)]
    #[UniqueEmail]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096)]
    public string $password = '';
}
