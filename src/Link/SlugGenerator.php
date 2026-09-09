<?php

declare(strict_types=1);

namespace App\Link;

/**
 * 7 characters from the base62 alphabet, from a cryptographically secure
 * source (FR-LNK-2). Candidates that hit the reserved list are regenerated.
 */
final class SlugGenerator implements SlugGeneratorInterface
{
    public const int LENGTH = 7;
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function generate(): string
    {
        do {
            $slug = '';
            for ($i = 0; $i < self::LENGTH; ++$i) {
                $slug .= self::ALPHABET[random_int(0, 61)];
            }
        } while (ReservedSlugs::contains($slug));

        return $slug;
    }
}
