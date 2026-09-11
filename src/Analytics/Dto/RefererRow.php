<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

final readonly class RefererRow
{
    public function __construct(
        public string $host,
        public int $clicks,
        public float $share,
        public int $rank,
    ) {
    }
}
