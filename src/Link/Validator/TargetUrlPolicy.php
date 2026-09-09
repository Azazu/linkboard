<?php

declare(strict_types=1);

namespace App\Link\Validator;

/**
 * The target URL policy as a pure function so the redirect and rules code
 * can reuse it without the validator component.
 *
 * Absolute URL, http or https, host present, at most 2048 characters, host
 * not `localhost`, literal IPs not loopback / link-local / private (v4 and
 * v6). Names are NOT resolved: a public hostname that resolves privately is
 * the documented residual risk (the server never fetches targets).
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
        $host = strtolower(trim($parts['host'], '[]'));
        if ('localhost' === $host || str_ends_with($host, '.localhost')) {
            return false;
        }

        $binary = @inet_pton($host);
        if (false === $binary) {
            return true; // a hostname: not resolved by design
        }
        if (4 === \strlen($binary)) {
            return !self::inAny($binary, self::BLOCKED_V4);
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d): apply the v4 table to the embedded address
        if (str_starts_with(bin2hex($binary), '00000000000000000000ffff')) {
            return !self::inAny(substr($binary, 12), self::BLOCKED_V4);
        }

        return !self::inAny($binary, self::BLOCKED_V6);
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
