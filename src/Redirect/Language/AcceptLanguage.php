<?php

declare(strict_types=1);

namespace App\Redirect\Language;

use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * FR-RUL-3: the visitor's language is the lower-cased two-letter primary
 * subtag of the highest-quality Accept-Language entry (design decision 9). The
 * header has already been classified as well-formed by VisitFactory; `*`, a
 * one-letter or three-letter primary subtag and an absent header are unknown.
 */
final class AcceptLanguage
{
    public static function primary(?string $header): ?string
    {
        if (null === $header || '' === trim($header)) {
            return null;
        }
        $items = AcceptHeader::fromString($header)->all(); // sorted by quality, then position
        $first = reset($items);
        if (false === $first) {
            return null;
        }
        if (1 === preg_match('/^([A-Za-z]{2})(?:[-_]|$)/D', $first->getValue(), $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    private function __construct()
    {
    }
}
