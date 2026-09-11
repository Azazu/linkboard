<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

final readonly class VariantRow
{
    public function __construct(
        public string $variant,
        public int $clicks,
        public int $uniqueVisitors,
        public float $share,
    ) {
    }
}
