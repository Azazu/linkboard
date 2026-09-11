<?php

declare(strict_types=1);

namespace App\Analytics\Api\Parameter;

use ApiPlatform\Metadata\QueryParameter;
use App\Analytics\Report\Granularity;
use Symfony\Component\Validator\Constraints as Assert;

/** `granularity` — bucket size of a timeseries; hourly buckets only over periods of at most 14 days. */
final class GranularityParameter extends QueryParameter
{
    public function __construct()
    {
        parent::__construct(
            key: 'granularity',
            schema: ['type' => 'string', 'enum' => ['hour', 'day'], 'default' => 'day'],
            description: 'Bucket size: `hour` (periods of at most 14 days) or `day`. Default day.',
            constraints: [new Assert\Choice(callback: [Granularity::class, 'values'], message: 'granularity must be hour or day.')],
        );
    }
}
