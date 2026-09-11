<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

final readonly class GlobalTotals
{
    public function __construct(
        public int $totalUsers,
        public int $totalLinks,
        public int $activeLinks,
        public int $totalClicks,
        public int $clicksToday,
    ) {
    }
}
