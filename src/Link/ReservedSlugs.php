<?php

declare(strict_types=1);

namespace App\Link;

/**
 * Slugs that would shadow a top-level route of the application (FR-LNK-3).
 * One constant, used by the Slug validator, the generator and the tests; a
 * test compares it with the router so a new top-level page cannot silently
 * become a valid slug.
 */
final class ReservedSlugs
{
    /** @var list<string> */
    public const array LIST = [
        'api', 'admin', 'login', 'logout', 'register', 'dashboard', 'links', 'api-keys',
        'health', 'docs', 'qr', 'assets', 'build', 'bundles', '_profiler', '_wdt', '_error',
        'index.php', 'favicon.ico', 'robots.txt',
    ];

    public static function contains(string $slug): bool
    {
        return \in_array($slug, self::LIST, true);
    }

    private function __construct()
    {
    }
}
