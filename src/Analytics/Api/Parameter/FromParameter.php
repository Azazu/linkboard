<?php

declare(strict_types=1);

namespace App\Analytics\Api\Parameter;

use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Validator\Constraints as Assert;

/** `from` — inclusive start of the report period, RFC 3339 (design decision 3). */
final class FromParameter extends QueryParameter
{
    public function __construct()
    {
        parent::__construct(
            key: 'from',
            schema: ['type' => 'string', 'format' => 'date-time'],
            description: 'Inclusive start of the period, RFC 3339 (e.g. 2026-09-01T00:00:00Z). Default: 30 days before `to`. The period is evaluated in UTC and may span at most 366 days.',
            constraints: [new Assert\DateTime(format: \DateTimeInterface::RFC3339, message: 'from must be an RFC 3339 timestamp, for example 2026-09-01T00:00:00Z.')],
        );
    }
}
