<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

/** Two breakdowns of the same period: by device type and by operating system. */
final readonly class Devices
{
    /**
     * @param list<DeviceTypeRow> $byDeviceType
     * @param list<OsRow>         $byOs
     */
    public function __construct(
        public int $total,
        public array $byDeviceType,
        public array $byOs,
    ) {
    }
}
