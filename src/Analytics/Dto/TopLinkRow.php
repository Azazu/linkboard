<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

final readonly class TopLinkRow
{
    public function __construct(
        public string $linkId,
        public string $slug,
        public string $ownerId,
        public int $clicks,
        public int $uniqueVisitors,
        public int $rank,
    ) {
    }
}
