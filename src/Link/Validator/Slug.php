<?php

declare(strict_types=1);

namespace App\Link\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * FR-LNK-3: ^[A-Za-z0-9_-]{3,32}$, not reserved, not taken (case-sensitive).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Slug extends Constraint
{
    public const string PATTERN = '/^[A-Za-z0-9_-]{3,32}$/';

    public string $formatMessage = 'A slug is 3 to 32 characters from A-Z, a-z, 0-9, "_" and "-".';
    public string $reservedMessage = 'This slug is reserved.';
    public string $takenMessage = 'This slug is already taken.';
}
