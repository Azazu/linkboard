<?php

declare(strict_types=1);

namespace App\Link;

/**
 * Filters and ordering of a link listing (FR-LNK-9). Built from validated
 * query parameters by the API providers; the repository never sees raw input.
 */
final readonly class LinkListQuery
{
    public const array ORDER_FIELDS = ['createdAt', 'clickCount'];
    public const array DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        public ?bool $isActive = null,
        public ?string $slugContains = null,
        public string $orderField = 'createdAt',
        public string $direction = 'desc',
    ) {
        if (!\in_array($orderField, self::ORDER_FIELDS, true) || !\in_array($direction, self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException('Unsupported order.');
        }
    }
}
