<?php

declare(strict_types=1);

namespace App\Auth\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;

/**
 * POST /api/v1/auth/register — creates an account. Public.
 *
 * The resource class is a marker: input is RegistrationInput, output is
 * UserOutput, the work happens in RegisterUserProcessor.
 */
#[ApiResource(
    shortName: 'Registration',
    operations: [
        new Post(
            uriTemplate: '/auth/register',
            input: RegistrationInput::class,
            output: UserOutput::class,
            processor: RegisterUserProcessor::class,
            security: 'is_granted("PUBLIC_ACCESS")',
            status: 201,
            description: 'Register an account with email and password (min 12 characters).',
        ),
    ],
)]
final class Registration
{
}
