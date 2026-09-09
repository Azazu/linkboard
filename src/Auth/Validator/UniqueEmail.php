<?php

declare(strict_types=1);

namespace App\Auth\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Rejects an email that already belongs to an account, comparing
 * case-insensitively like the database index does. The index remains the
 * authority under concurrency; this constraint gives the 422 a proper
 * violation before the insert is attempted.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UniqueEmail extends Constraint
{
    public string $message = 'An account with this email already exists.';
}
