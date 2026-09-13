<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Link\Rules\RuleViolation;

/**
 * Where a rules-document violation belongs on the form (design decision 8 of
 * add-web-ui). The parser reports a path into the document — `[rules][0][target]` —
 * and the structured editor has a field for exactly that: row 0's target. A
 * path the rows cannot hold, and every path in raw mode, goes on the document
 * itself with the path kept in the message, so nothing is lost in translation.
 */
final class RuleViolationMapper
{
    private function __construct()
    {
    }

    /**
     * @return array{0: ?int, 1: ?string} the row index and the row's field, or [null, null] for the document
     */
    public static function locate(string $path): array
    {
        if (1 !== preg_match('/^\[rules\]\[(\d+)\](?:\[(match|target)\].*)?$/', $path, $m)) {
            return [null, null];
        }
        $field = ($m[2] ?? '') === 'match' ? 'values' : (($m[2] ?? '') === 'target' ? 'target' : null);

        return [(int) $m[1], $field];
    }

    /** The message a person reads: the parser's text, with the document path when it has nowhere to sit. */
    public static function message(RuleViolation $violation, bool $located): string
    {
        return $located ? $violation->message : \sprintf('%s %s', $violation->path, $violation->message);
    }
}
