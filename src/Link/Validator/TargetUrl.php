<?php

declare(strict_types=1);

namespace App\Link\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * FR-LNK-5 / D8: the target URL policy (open-redirect and SSRF classes).
 * Reused for rule and variant targets by the routing-rules change.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class TargetUrl extends Constraint
{
    public const int MAX_LENGTH = 2048;

    public string $message = 'The target must be an absolute http(s) URL to a public host, at most 2048 characters.';
}
