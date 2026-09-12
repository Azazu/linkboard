<?php

declare(strict_types=1);

namespace App\Auth\ApiKey;

/**
 * FR-KEY-1: `lb_` + 40 characters of [A-Za-z0-9] drawn uniformly with
 * random_int (CSPRNG; log2(62^40) ≈ 238 bits). Stored as SHA-256 hex and an
 * 8-character display prefix; the plaintext lives only in the creation
 * response (design decision 2).
 */
final class ApiKeyGenerator
{
    public const string PREFIX = 'lb_';
    public const int BODY_LENGTH = 40;
    public const string PATTERN = '/^lb_[A-Za-z0-9]{40}$/';
    public const int DISPLAY_PREFIX_LENGTH = 8;

    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function generate(): GeneratedKey
    {
        $body = '';
        $max = \strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::BODY_LENGTH; ++$i) {
            $body .= self::ALPHABET[random_int(0, $max)];
        }
        $plaintext = self::PREFIX.$body;

        return new GeneratedKey($plaintext, self::hash($plaintext), substr($plaintext, 0, self::DISPLAY_PREFIX_LENGTH));
    }

    /** The stored form of a presented key — the only comparison that ever happens is on this value. */
    public static function hash(#[\SensitiveParameter] string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
