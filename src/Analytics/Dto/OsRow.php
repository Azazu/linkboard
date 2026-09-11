<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

final readonly class OsRow
{
    public function __construct(
        public ?string $os,
        public int $clicks,
        public float $share,
    ) {
    }
}
