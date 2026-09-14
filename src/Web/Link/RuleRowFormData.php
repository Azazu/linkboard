<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Link\Rules\RulesDocument;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One row of the structured rules editor: a single match key, its values and
 * the target that wins. The document's own limits are checked by
 * RulesDocumentParser — these constraints only keep obviously empty rows from
 * reaching it, so the messages a person sees come from one place.
 */
final class RuleRowFormData
{
    #[Assert\NotBlank(message: 'Choose what this rule matches on.')]
    #[Assert\Choice(choices: RulesDocument::MATCH_KEYS, message: 'Unknown match key.')]
    public ?string $matchKey = null;

    /** Comma-separated: `smartphone, tablet` or `DE, AT`. */
    #[Assert\NotBlank(message: 'Give at least one value.')]
    public ?string $values = null;

    #[Assert\NotBlank(message: 'A rule needs a target URL.')]
    #[Assert\Length(max: RulesDocument::TARGET_MAX_LENGTH)]
    public ?string $target = null;

    /** @return list<string> */
    public function valueList(): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', (string) $this->values)), static fn (string $v): bool => '' !== $v));
    }
}
