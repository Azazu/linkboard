<?php

declare(strict_types=1);

namespace App\Link\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * FR-RUL-1: the routing-rules document of a link request. Validated from the
 * raw request body (RulesInput), not from the annotated property's value.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ValidRules extends Constraint
{
    public string $notAnObjectMessage = 'The rules must be a JSON object or null.';
}
