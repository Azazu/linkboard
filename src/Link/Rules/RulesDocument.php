<?php

declare(strict_types=1);

namespace App\Link\Rules;

/**
 * A validated routing-rules document (FR-RUL-2/3). The constants are the one
 * source of the limits and vocabularies: the parser enforces them, the
 * published JSON Schema (docs/reference/rules-schema.json) restates them and
 * a parity test keeps the two equal. toArray() is the canonical stored form.
 */
final readonly class RulesDocument
{
    public const int VERSION = 1;
    public const int MIN_RULES = 1;
    public const int MAX_RULES = 20;
    public const int MIN_VALUES = 1;
    public const int MAX_VALUES = 64;
    public const int MIN_VARIANTS = 2;
    public const int MAX_VARIANTS = 4;
    public const int MIN_WEIGHT = 1;
    public const int WEIGHT_SUM = 100;
    public const int TARGET_MAX_LENGTH = 2048;
    /** PHP regexes with the D modifier: `$` means the very end, as in JSON Schema */
    public const string NAME_PATTERN = '/^[A-Za-z0-9_-]{1,16}$/D';
    public const string COUNTRY_PATTERN = '/^[A-Z]{2}$/D';
    public const string LANGUAGE_PATTERN = '/^[a-z]{2}$/D';
    /** @var list<string> */
    public const array DEVICES = ['desktop', 'smartphone', 'tablet', 'other'];
    /** @var list<string> */
    public const array OSES = ['iOS', 'Android', 'Windows', 'macOS', 'Linux', 'other'];
    /** @var list<string> canonical order of match keys */
    public const array MATCH_KEYS = ['device', 'os', 'country', 'language'];

    /**
     * @param list<Rule>    $rules
     * @param list<Variant> $variants
     */
    public function __construct(
        public array $rules,
        public array $variants,
    ) {
    }

    /** @phpstan-assert-if-true non-empty-list<Variant> $this->variants */
    public function hasVariants(): bool
    {
        return [] !== $this->variants;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['version' => self::VERSION];
        if ([] !== $this->rules) {
            $out['rules'] = array_map(static fn (Rule $r): array => $r->toArray(), $this->rules);
        }
        if ([] !== $this->variants) {
            $out['variants'] = array_map(static fn (Variant $v): array => $v->toArray(), $this->variants);
        }

        return $out;
    }
}
