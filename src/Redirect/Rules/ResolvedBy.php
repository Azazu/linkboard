<?php

declare(strict_types=1);

namespace App\Redirect\Rules;

/** What resolved the destination (FR-RUL-5); stored as clicks.resolved_by. */
enum ResolvedBy: string
{
    case Device = 'device';
    case Country = 'country';
    case Language = 'language';
    case Variant = 'variant';
    case Default = 'default';
}
