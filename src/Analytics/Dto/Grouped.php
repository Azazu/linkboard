<?php

declare(strict_types=1);

namespace App\Analytics\Dto;

/**
 * A grouped report: the period's total over every group (not only the
 * returned ones) and the rows in descending order of clicks.
 *
 * @template T of object
 */
final readonly class Grouped
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public int $total,
        public array $items,
    ) {
    }
}
