<?php

declare(strict_types=1);

namespace App\Web\Link;

/**
 * The routing rules as the edit form carries them: either a list of structured
 * rows or the raw JSON document, with `mode` saying which one the submission
 * meant (spec web-ui — "The routing-rules editor accepts a document and shows
 * its violations"). Whichever arrives, the server validates the same document
 * with the same parser.
 *
 * A stored document the structured editor cannot represent — a rule matching
 * on several keys at once, or A/B variants — opens in raw mode, because
 * rendering it as rows would quietly drop what the rows cannot hold. The mode
 * is submitted with the form, so what a person chose is what the server reads
 * (Gate 2 round 1, finding 1); an unknown value means the structured view.
 */
final class RulesFormData
{
    public const string MODE_STRUCTURED = 'structured';
    public const string MODE_RAW = 'raw';

    /** Nullable on purpose: a submission that carries no choice at all — a partial or forged POST — is the structured view, not a type error. */
    public ?string $mode = self::MODE_STRUCTURED;

    /** @var list<RuleRowFormData> */
    public array $rows = [];

    public ?string $raw = null;

    /**
     * Not `isRaw()`: PropertyAccess would take that for the accessor of the
     * `raw` property and render the boolean into the document field — which is
     * exactly what it did until a test looked at what the field contained.
     */
    public function usesRawDocument(): bool
    {
        return self::MODE_RAW === $this->mode;
    }
}
