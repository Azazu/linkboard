<?php

declare(strict_types=1);

namespace App\Redirect\Rules;

use App\Click\Visit;
use App\Link\Rules\Dimension;
use App\Link\Rules\Rule;
use App\Link\Rules\RulesDocumentParser;
use App\Redirect\VisitorProfile;
use Symfony\Component\Uid\Uuid;

/**
 * FR-RUL-4: fixed order — device-dimension rules, then country, then language,
 * each in document order; a rule matches when every value list it names
 * contains the visitor's value (a null dimension skips the rule); then the
 * A/B variants; then the link's targetUrl. Pure; throws UnusableRulesException
 * for a stored document the parser rejects (design decisions 5–6).
 */
final readonly class RuleEvaluator
{
    private const array ORDER = [Dimension::Device, Dimension::Country, Dimension::Language];

    public function __construct(
        private RulesDocumentParser $parser,
    ) {
    }

    /**
     * @param array<string, mixed>|null $storedRules the link's `rules` column
     */
    public function evaluate(?array $storedRules, VisitorProfile $profile, Visit $visit, Uuid $linkId, string $targetUrl): Resolution
    {
        if (null === $storedRules) {
            return Resolution::default($targetUrl);
        }
        $result = $this->parser->parse($storedRules);
        $document = $result->document ?? throw new UnusableRulesException(\sprintf('The stored rules document has %d violation(s), the first at "%s".', \count($result->violations), $result->violations[0]->path ?? ''));

        foreach (self::ORDER as $dimension) {
            foreach ($document->rules as $rule) {
                if ($rule->dimension === $dimension && self::matches($rule, $profile)) {
                    return new Resolution($rule->target, ResolvedBy::from($dimension->value));
                }
            }
        }
        if ($document->hasVariants()) {
            $variant = VariantPicker::pick($document->variants, $linkId, $visit->clientIp, $visit->userAgent);

            return new Resolution($variant->target, ResolvedBy::Variant, $variant->name);
        }

        return Resolution::default($targetUrl);
    }

    private static function matches(Rule $rule, VisitorProfile $profile): bool
    {
        foreach ($rule->match as $key => $values) {
            $value = match ($key) {
                'device' => $profile->deviceType,
                'os' => $profile->os,
                'country' => $profile->country,
                'language' => $profile->language,
                default => null,
            };
            if (null === $value || !\in_array($value, $values, true)) {
                return false; // unresolved dimension or no match: never a match (FR-RUL-4)
            }
        }

        return true;
    }
}
