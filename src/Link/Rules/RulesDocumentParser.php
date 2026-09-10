<?php

declare(strict_types=1);

namespace App\Link\Rules;

use App\Link\Validator\TargetUrlPolicy;

/**
 * The one authority for the document's shape (design decision 1), used by
 * write validation and by the redirect. It walks a type-preserving JSON tree:
 * objects are \stdClass (json_decode with objects) and arrays are PHP lists.
 * For a stored document — the canonical toArray() form decoded associatively —
 * a non-empty string-keyed PHP array is an object; that reading trusts the
 * writer for JSON kinds (a numeric-keyed object is indistinguishable from a
 * list after associative decoding and can only be rejected at write time).
 */
final class RulesDocumentParser
{
    public function parse(mixed $node): ParseResult
    {
        $members = self::objectMembers($node);
        if (null === $members || [] === $members) {
            return ParseResult::invalid([new RuleViolation('', 'The rules document must be an object with "version" and "rules" or "variants".')]);
        }

        $violations = [];
        if (RulesDocument::VERSION !== ($members['version'] ?? null)) {
            $violations[] = new RuleViolation('[version]', \sprintf('The document version must be the integer %d.', RulesDocument::VERSION));
        }
        foreach (array_keys($members) as $key) {
            if (!\in_array((string) $key, ['version', 'rules', 'variants'], true)) {
                $violations[] = new RuleViolation('['.$key.']', 'Unknown member.');
            }
        }
        $hasRules = \array_key_exists('rules', $members);
        $hasVariants = \array_key_exists('variants', $members);
        if (!$hasRules && !$hasVariants) {
            $violations[] = new RuleViolation('', 'The document must contain "rules" or "variants".');
        }
        $rules = $hasRules ? $this->parseRules($members['rules'], $violations) : [];
        $variants = $hasVariants ? $this->parseVariants($members['variants'], $violations) : [];

        return [] === $violations ? ParseResult::valid(new RulesDocument($rules, $variants)) : ParseResult::invalid($violations);
    }

    /**
     * @param list<RuleViolation> $violations
     *
     * @return list<Rule>
     */
    private function parseRules(mixed $node, array &$violations): array
    {
        if (!self::isList($node)) {
            $violations[] = new RuleViolation('[rules]', 'Rules must be a JSON array.');

            return [];
        }
        if (\count($node) < RulesDocument::MIN_RULES || \count($node) > RulesDocument::MAX_RULES) {
            $violations[] = new RuleViolation('[rules]', \sprintf('Between %d and %d rules are allowed.', RulesDocument::MIN_RULES, RulesDocument::MAX_RULES));

            return [];
        }

        $rules = [];
        foreach ($node as $i => $ruleNode) {
            $path = '[rules]['.$i.']';
            $members = self::objectMembers($ruleNode);
            if (null === $members) {
                $violations[] = new RuleViolation($path, 'A rule must be an object with "match" and "target".');
                continue;
            }
            foreach (array_keys($members) as $key) {
                if (!\in_array((string) $key, ['match', 'target'], true)) {
                    $violations[] = new RuleViolation($path.'['.$key.']', 'Unknown member.');
                }
            }
            $match = \array_key_exists('match', $members) ? $this->parseMatch($members['match'], $path.'[match]', $violations) : null;
            if (null === $match && !\array_key_exists('match', $members)) {
                $violations[] = new RuleViolation($path.'[match]', 'A rule must have a "match".');
            }
            $target = $this->parseTarget($members['target'] ?? null, $path.'[target]', $violations);
            if (null !== $match && null !== $target) {
                $dimension = Dimension::ofMatchKeys(array_keys($match));
                \assert(null !== $dimension); // parseMatch enforces exactly one dimension
                $rules[] = new Rule($dimension, $match, $target);
            }
        }

        return $rules;
    }

    /**
     * @param list<RuleViolation> $violations
     *
     * @return array<string, list<string>>|null canonical key order, or null when invalid
     */
    private function parseMatch(mixed $node, string $path, array &$violations): ?array
    {
        $members = self::objectMembers($node);
        if (null === $members && !($node instanceof \stdClass)) {
            $violations[] = new RuleViolation($path, 'A match must be an object naming exactly one dimension.');

            return null;
        }
        $members ??= [];
        $valid = true;
        foreach (array_keys($members) as $key) {
            if (!\in_array((string) $key, RulesDocument::MATCH_KEYS, true)) {
                $violations[] = new RuleViolation($path.'['.$key.']', 'Unknown dimension.');
                $valid = false;
            }
        }
        /** @var list<string> $present */
        $present = array_values(array_filter(RulesDocument::MATCH_KEYS, static fn (string $k): bool => \array_key_exists($k, $members)));
        if (null === Dimension::ofMatchKeys($present)) {
            $violations[] = new RuleViolation($path, 'A match must name exactly one dimension: device (device and/or os), country, or language.');
            $valid = false;
        }
        $match = [];
        foreach ($present as $key) {
            $values = $this->parseValues($key, $members[$key], $path.'['.$key.']', $violations);
            if (null === $values) {
                $valid = false;
                continue;
            }
            $match[$key] = $values;
        }

        return $valid ? $match : null;
    }

    /**
     * @param list<RuleViolation> $violations
     *
     * @return list<string>|null
     */
    private function parseValues(string $key, mixed $node, string $path, array &$violations): ?array
    {
        if (!self::isList($node)) {
            $violations[] = new RuleViolation($path, 'Values must be a JSON array.');

            return null;
        }
        if (\count($node) < RulesDocument::MIN_VALUES || \count($node) > RulesDocument::MAX_VALUES) {
            $violations[] = new RuleViolation($path, \sprintf('Between %d and %d values are allowed.', RulesDocument::MIN_VALUES, RulesDocument::MAX_VALUES));

            return null;
        }
        $values = [];
        $valid = true;
        foreach ($node as $j => $value) {
            $itemPath = $path.'['.$j.']';
            if (!\is_string($value) || !self::isVocabularyValue($key, $value)) {
                $violations[] = new RuleViolation($itemPath, match ($key) {
                    'device' => 'Allowed devices: '.implode(', ', RulesDocument::DEVICES).'.',
                    'os' => 'Allowed operating systems: '.implode(', ', RulesDocument::OSES).'.',
                    'country' => 'A country is an upper-case ISO 3166-1 alpha-2 code.',
                    default => 'A language is a lower-case ISO 639-1 code.',
                });
                $valid = false;
                continue;
            }
            if (\in_array($value, $values, true)) {
                $violations[] = new RuleViolation($itemPath, 'Duplicate value.');
                $valid = false;
                continue;
            }
            $values[] = $value;
        }

        return $valid ? $values : null;
    }

    private static function isVocabularyValue(string $key, string $value): bool
    {
        return match ($key) {
            'device' => \in_array($value, RulesDocument::DEVICES, true),
            'os' => \in_array($value, RulesDocument::OSES, true),
            'country' => 1 === preg_match(RulesDocument::COUNTRY_PATTERN, $value),
            default => 1 === preg_match(RulesDocument::LANGUAGE_PATTERN, $value),
        };
    }

    /**
     * @param list<RuleViolation> $violations
     */
    private function parseTarget(mixed $node, string $path, array &$violations): ?string
    {
        if (!\is_string($node) || \strlen($node) > RulesDocument::TARGET_MAX_LENGTH || !TargetUrlPolicy::isAllowed($node)) {
            $violations[] = new RuleViolation($path, \sprintf('The target must be an absolute http(s) URL to a public host, at most %d characters.', RulesDocument::TARGET_MAX_LENGTH));

            return null;
        }

        return $node;
    }

    /**
     * @param list<RuleViolation> $violations
     *
     * @return list<Variant>
     */
    private function parseVariants(mixed $node, array &$violations): array
    {
        if (!self::isList($node)) {
            $violations[] = new RuleViolation('[variants]', 'Variants must be a JSON array.');

            return [];
        }
        $countValid = \count($node) >= RulesDocument::MIN_VARIANTS && \count($node) <= RulesDocument::MAX_VARIANTS;
        if (!$countValid) {
            $violations[] = new RuleViolation('[variants]', \sprintf('Between %d and %d variants are allowed.', RulesDocument::MIN_VARIANTS, RulesDocument::MAX_VARIANTS));
        }

        $variants = [];
        $names = [];
        $weightsValid = true;
        $sum = 0;
        foreach ($node as $i => $variantNode) {
            $path = '[variants]['.$i.']';
            $members = self::objectMembers($variantNode);
            if (null === $members) {
                $violations[] = new RuleViolation($path, 'A variant must be an object with "name", "weight" and "target".');
                $weightsValid = false;
                continue;
            }
            foreach (array_keys($members) as $key) {
                if (!\in_array((string) $key, ['name', 'weight', 'target'], true)) {
                    $violations[] = new RuleViolation($path.'['.$key.']', 'Unknown member.');
                }
            }
            $name = $members['name'] ?? null;
            if (!\is_string($name) || 1 !== preg_match(RulesDocument::NAME_PATTERN, $name)) {
                $violations[] = new RuleViolation($path.'[name]', 'A variant name is 1 to 16 characters of [A-Za-z0-9_-].');
                $name = null;
            } elseif (\in_array($name, $names, true)) {
                $violations[] = new RuleViolation($path.'[name]', 'Variant names must be distinct.');
                $name = null;
            } else {
                $names[] = $name;
            }
            $weight = $members['weight'] ?? null;
            if (!\is_int($weight) || $weight < RulesDocument::MIN_WEIGHT) {
                $violations[] = new RuleViolation($path.'[weight]', \sprintf('A weight is an integer of at least %d.', RulesDocument::MIN_WEIGHT));
                $weightsValid = false;
                $weight = null;
            } else {
                $sum += $weight;
            }
            $target = $this->parseTarget($members['target'] ?? null, $path.'[target]', $violations);
            if (null !== $name && null !== $weight && null !== $target) {
                $variants[] = new Variant($name, $weight, $target);
            }
        }
        if ($countValid && $weightsValid && RulesDocument::WEIGHT_SUM !== $sum) {
            $violations[] = new RuleViolation('[variants]', \sprintf('Variant weights must sum to %d.', RulesDocument::WEIGHT_SUM));
        }

        return $variants;
    }

    /**
     * Members of a JSON object node, or null when the node is not an object.
     * \stdClass is the type-preserving form; a non-empty string-keyed array is
     * the stored form. Numeric property names come back as integer keys and
     * are reported as unknown members by the callers.
     *
     * @return array<int|string, mixed>|null
     */
    private static function objectMembers(mixed $node): ?array
    {
        if ($node instanceof \stdClass) {
            return get_object_vars($node);
        }
        if (\is_array($node) && [] !== $node && !array_is_list($node)) {
            return $node;
        }

        return null;
    }

    /**
     * @phpstan-assert-if-true list<mixed> $node
     */
    private static function isList(mixed $node): bool
    {
        return \is_array($node) && array_is_list($node);
    }
}
