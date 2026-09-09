<?php

declare(strict_types=1);

namespace App\Redirect;

/**
 * FR-LNK-6 / D9: appends the link's UTM keys to the destination (design
 * decision 6). The query is handled as raw `key=value` pairs, never as a
 * decoded map: parse_str/http_build_query would collapse repeated keys and
 * rewrite `a.b` to `a_b`, changing the destination. Pairs whose
 * percent-decoded key is one of the link's UTM keys are dropped in every
 * spelling, everything else is kept byte for byte and in order, then the
 * link's UTM pairs are appended in a fixed order. The fragment is kept.
 */
final class UtmAppender
{
    /** @var list<string> the fixed order of appended keys */
    public const array KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * @param array<string, string> $utm the link's UTM (validated on write: known keys, strings ≤ 255)
     */
    public static function append(string $target, array $utm): string
    {
        $utm = array_intersect_key($utm, array_flip(self::KEYS));
        if ([] === $utm) {
            return $target;
        }

        $fragment = null;
        $hash = strpos($target, '#');
        if (false !== $hash) {
            $fragment = substr($target, $hash + 1);
            $target = substr($target, 0, $hash);
        }
        $query = '';
        $qmark = strpos($target, '?');
        if (false !== $qmark) {
            $query = substr($target, $qmark + 1);
            $target = substr($target, 0, $qmark);
        }

        $pairs = [];
        foreach ('' === $query ? [] : explode('&', $query) as $pair) {
            $eq = strpos($pair, '=');
            $rawKey = false === $eq ? $pair : substr($pair, 0, $eq);
            if (\array_key_exists(rawurldecode($rawKey), $utm)) {
                continue; // the link's value wins, in every spelling of the key
            }
            $pairs[] = $pair;
        }
        foreach (self::KEYS as $key) {
            if (isset($utm[$key])) {
                $pairs[] = $key.'='.rawurlencode($utm[$key]);
            }
        }

        $result = $target.'?'.implode('&', $pairs);

        return null === $fragment ? $result : $result.'#'.$fragment;
    }

    private function __construct()
    {
    }
}
