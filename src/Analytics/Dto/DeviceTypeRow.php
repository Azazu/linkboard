<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

final readonly class DeviceTypeRow
{
    public function __construct(
        public ?string $deviceType,
        public int $clicks,
        public float $share,
    ) {
    }
}
