<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Link\Rules\RulesDocument;

/**
 * Between the stored rules document and the two views of the editor
 * (design decision 8 of add-web-ui). Nothing here validates: the node this
 * builds goes to RulesDocumentParser, which is the one authority on what a
 * document may contain.
 */
final class RulesDocumentMapper
{
    private function __construct()
    {
    }

    /**
     * The form's view of a stored document. A document with variants, or with a
     * rule that matches on more than one key, is shown raw — the rows cannot
     * hold it, and silently dropping half a document is worse than a textarea.
     *
     * @param array<string, mixed>|null $stored canonical form
     */
    public static function toForm(?array $stored): RulesFormData
    {
        $data = new RulesFormData();
        if (null === $stored || [] === $stored) {
            return $data;
        }

        $data->raw = json_encode($stored, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $rules = $stored['rules'] ?? [];
        if (isset($stored['variants']) || !\is_array($rules) || [] === $rules) {
            $data->mode = RulesFormData::MODE_RAW;

            return $data;
        }

        foreach ($rules as $rule) {
            $match = \is_array($rule) ? ($rule['match'] ?? null) : null;
            if (!\is_array($match) || 1 !== \count($match)) {
                $data->mode = RulesFormData::MODE_RAW;
                $data->rows = [];

                return $data;
            }
            $key = array_key_first($match);
            $row = new RuleRowFormData();
            $row->matchKey = \is_string($key) ? $key : null;
            $row->values = implode(', ', array_map(strval(...), \is_array($match[$key]) ? $match[$key] : []));
            $row->target = \is_array($rule) && \is_string($rule['target'] ?? null) ? $rule['target'] : null;
            $data->rows[] = $row;
        }

        return $data;
    }

    /**
     * The document a submission means, in the shape RulesDocumentParser reads.
     * Returns null when the editor is empty — which clears the link's rules.
     *
     * @throws \JsonException when raw mode carries text that is not JSON at all
     */
    public static function toNode(RulesFormData $data): mixed
    {
        if ($data->isRaw()) {
            $raw = trim((string) $data->raw);

            return '' === $raw ? null : json_decode($raw, false, 512, \JSON_THROW_ON_ERROR);
        }

        $rows = array_values(array_filter($data->rows, static fn (RuleRowFormData $row): bool => null !== $row->matchKey || null !== $row->target || null !== $row->values));
        if ([] === $rows) {
            return null;
        }

        return [
            'version' => RulesDocument::VERSION,
            'rules' => array_map(static fn (RuleRowFormData $row): array => [
                'match' => [(string) $row->matchKey => $row->valueList()],
                'target' => (string) $row->target,
            ], $rows),
        ];
    }
}
