<?php

declare(strict_types=1);

namespace App\Link\Rules;

/**
 * One routing rule: a match over exactly one dimension and a target. The
 * match keeps only the keys the document named, in canonical order
 * (`device`, `os`, `country`, `language`); a rule matches a visitor when every
 * listed value list contains the visitor's resolved value for that key.
 */
final readonly class Rule
{
    /**
     * @param array<string, list<string>> $match
     */
    public function __construct(
        public Dimension $dimension,
        public array $match,
        public string $target,
    ) {
    }

    /**
     * @return array{match: array<string, list<string>>, target: string}
     */
    public function toArray(): array
    {
        return ['match' => $this->match, 'target' => $this->target];
    }
}
