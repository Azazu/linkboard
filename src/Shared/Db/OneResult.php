<?php

declare(strict_types=1);

namespace App\Shared\Db;

/**
 * Narrows what `getOneOrNullResult()` returns.
 *
 * Doctrine types that method as `mixed`, so a repository promising
 * `?ApiKey` was returning `mixed` as far as the analyser could tell. The
 * narrowing is a runtime check rather than a PHPDoc assertion, because a
 * PHPDoc assertion is only as true as the DQL beside it, and this one holds in
 * production too (change harden-gate-floor).
 */
final class OneResult
{
    private function __construct()
    {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function orNull(mixed $result, string $class): ?object
    {
        if (null === $result) {
            return null;
        }

        if ($result instanceof $class) {
            return $result;
        }

        throw new \LogicException(\sprintf('Expected %s or null from the query, got %s.', $class, get_debug_type($result)));
    }
}
