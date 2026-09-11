<?php

declare(strict_types=1);

namespace App\Analytics\Api\Parameter;

use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Validator\Constraints as Assert;

/** `to` — exclusive end of the report period, RFC 3339 (design decision 3). */
final class ToParameter extends QueryParameter
{
    public function __construct()
    {
        parent::__construct(
            key: 'to',
            schema: ['type' => 'string', 'format' => 'date-time'],
            description: 'Exclusive end of the period, RFC 3339. Default: the start of the next UTC day, so the default period is the current UTC day and the 29 before it.',
            constraints: [new Assert\DateTime(format: \DateTimeInterface::RFC3339, message: 'to must be an RFC 3339 timestamp, for example 2026-09-08T00:00:00Z.')],
        );
    }
}
