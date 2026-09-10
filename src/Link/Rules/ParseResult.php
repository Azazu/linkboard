<?php

declare(strict_types=1);

namespace App\Link\Rules;

final readonly class ParseResult
{
    /**
     * @param list<RuleViolation> $violations
     */
    private function __construct(
        public ?RulesDocument $document,
        public array $violations,
    ) {
    }

    public static function valid(RulesDocument $document): self
    {
        return new self($document, []);
    }

    /**
     * @param non-empty-list<RuleViolation> $violations
     */
    public static function invalid(array $violations): self
    {
        return new self(null, $violations);
    }

    public function isValid(): bool
    {
        return null !== $this->document;
    }
}
