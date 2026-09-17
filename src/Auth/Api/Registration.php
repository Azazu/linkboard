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
    // Deliberately out of the GraphQL schema (change stretch-graphql): an
    // empty list is the exclusion, because a resource that declares none
    // receives the framework's default set — two queries AND the mutations
    // that create, update and delete it.
    graphQlOperations: [],
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
