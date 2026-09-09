<?php

declare(strict_types=1);

namespace App\Link\Validator;

/**
 * The target URL policy as a pure function so the redirect and rules code
 * can reuse it without the validator component.
 *
 * Absolute URL, http or https, host present, at most 2048 characters, host
 * not `localhost`, literal IPs not loopback / link-local / private (v4 and
 * v6). A literal IP is recognised in every form a browser accepts, not only
 * dotted quads: the WHATWG URL host parser treats a host whose last label is
 * a number as IPv4 (`127.1`, `2130706433`, `0x7f000001`, `0177.0.0.1`,
 * trailing dot), so those are canonicalised before the range check and
 * rejected outright when they are not a valid IPv4. Percent-encoded hosts
 * are rejected: browsers decode them, parse_url does not, and an address
 * must never be judged on a spelling the client will not use. Names are NOT
 * resolved: a public hostname that resolves privately is the documented
 * residual risk (the server never fetches targets).
 */
final class TargetUrlPolicy
{
    /** @var list<array{string, int}> network/prefix-length, the explicit table is the primary oracle */
    private const array BLOCKED_V4 = [
        ['127.0.0.0', 8],     // loopback
        ['10.0.0.0', 8],      // private
        ['172.16.0.0', 12],   // private
        ['192.168.0.0', 16],  // private
        ['169.254.0.0', 16],  // link-local (cloud metadata lives here)
        ['0.0.0.0', 8],       // "this" network
    ];

    /** @var list<array{string, int}> */
    private const array BLOCKED_V6 = [
        ['::1', 128],     // loopback
        ['::', 128],      // unspecified
        ['fe80::', 10],   // link-local
        ['fc00::', 7],    // unique local (private)
        ['::ffff:0:0', 96], // IPv4-mapped: checked as v4 below, blocked here as a whole for simplicity
    ];

    public static function isAllowed(string $url): bool
    {
        if (\strlen($url) > TargetUrl::MAX_LENGTH || preg_match('/[\s]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || '' === $parts['host']) {
            return false;
        }
        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        $rawHost = $parts['host'];
        if (str_contains($rawHost, '%')) {
            return false; // percent-encoded host: ambiguous between parsers
        }
        if (str_starts_with($rawHost, '[')) {
            $binary = @inet_pton(trim($rawHost, '[]'));

            return false !== $binary && !self::isBlockedAddress($binary);
        }

        $host = strtolower(rtrim($rawHost, '.')); // a trailing dot names the same host
        if ('' === $host || 'localhost' === $host || str_ends_with($host, '.localhost')) {
            return false;
        }

        $ipv4 = self::parseIpv4Host($host);
        if (null === $ipv4) {
            return true; // a hostname: not resolved by design
        }

        return false !== $ipv4 && !self::isBlockedAddress(pack('N', $ipv4));
    }

    private static function isBlockedAddress(string $binary): bool
    {
        if (4 === \strlen($binary)) {
            return self::inAny($binary, self::BLOCKED_V4);
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d): apply the v4 table to the embedded address
        if (str_starts_with(bin2hex($binary), '00000000000000000000ffff')) {
            return self::inAny(substr($binary, 12), self::BLOCKED_V4);
        }

        return self::inAny($binary, self::BLOCKED_V6);
    }

    /**
     * The WHATWG URL "IPv4 parser" (host parsing, steps "ends in a number" and
     * "IPv4 number parser"): 1 to 4 dot-separated numbers, each decimal, octal
     * (leading 0) or hex (0x), the last one filling the remaining bytes.
     *
     * @return int|false|null the address; false when the host ends in a number
     *                        but is not a valid IPv4 (browsers fail such URLs);
     *                        null when it is a hostname
     */
    private static function parseIpv4Host(string $host): int|false|null
    {
        $labels = explode('.', $host);
        $last = $labels[array_key_last($labels)];
        if (1 !== preg_match('/^(?:[0-9]+|0x[0-9a-f]*)\z/', $last)) {
            return null;
        }
        if (\count($labels) > 4) {
            return false;
        }

        $numbers = [];
        foreach ($labels as $label) {
            if (1 === preg_match('/^0x([0-9a-f]*)\z/', $label, $m)) {
                $n = '' === $m[1] ? 0 : hexdec($m[1]);
            } elseif (1 === preg_match('/^0[0-7]+\z/', $label)) {
                $n = octdec($label);
            } elseif (1 === preg_match('/^(?:0|[1-9][0-9]*)\z/', $label)) {
                $n = \strlen($label) > 10 ? \PHP_FLOAT_MAX : (int) $label;
            } else {
                return false; // e.g. "08": a leading zero means octal, and 8 is not an octal digit
            }
            if (!\is_int($n) || $n > 0xFFFFFFFF) {
                return false; // hexdec/octdec return float on overflow
            }
            $numbers[] = $n;
        }

        $lastNumber = array_pop($numbers);
        foreach ($numbers as $n) {
            if ($n > 255) {
                return false;
            }
        }
        if ($lastNumber >= 256 ** (4 - \count($numbers))) {
            return false;
        }
        $address = $lastNumber;
        foreach ($numbers as $i => $n) {
            $address += $n * 256 ** (3 - $i);
        }

        return $address;
    }

    /**
     * @param list<array{string, int}> $table
     */
    private static function inAny(string $binary, array $table): bool
    {
        foreach ($table as [$network, $bits]) {
            $net = inet_pton($network);
            if (false === $net || \strlen($net) !== \strlen($binary)) {
                continue;
            }
            if (self::prefixMatches($binary, $net, $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function prefixMatches(string $a, string $b, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        if (substr($a, 0, $fullBytes) !== substr($b, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if (0 === $rest) {
            return true;
        }
        $mask = 0xFF & (0xFF << (8 - $rest));

        return (\ord($a[$fullBytes]) & $mask) === (\ord($b[$fullBytes]) & $mask);
    }

    private function __construct()
    {
    }
}
