<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

final readonly class CountryRow
{
    public function __construct(
        public ?string $country,
        public int $clicks,
        public float $share,
        public int $rank,
    ) {
    }
}
