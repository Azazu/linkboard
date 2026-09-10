<?php

declare(strict_types=1);

namespace App\Link\Rules;

final readonly class Variant
{
    public function __construct(
        public string $name,
        public int $weight,
        public string $target,
    ) {
    }

    /**
     * @return array{name: string, weight: int, target: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'weight' => $this->weight, 'target' => $this->target];
    }
}
