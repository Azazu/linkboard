<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

/**
 * The figures on a signed-in owner's dashboard: their links and the clicks on
 * them. Instance-wide numbers are GlobalTotals and belong to an administrator.
 */
final readonly class OwnerTotals
{
    public function __construct(
        public int $links,
        public int $activeLinks,
        public int $clicks,
        public int $uniqueVisitors,
        public int $clicksToday,
    ) {
    }
}
