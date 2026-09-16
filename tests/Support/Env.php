<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * An environment variable a test needs, as a string.
 *
 * `$_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL']` is `mixed`, and casting it hid
 * the case worth knowing about: the variable is not set at all, and the test
 * connects to `''` and fails with a message about a connection rather than
 * about its environment (change harden-gate-floor).
 */
final class Env
{
    private function __construct()
    {
    }

    public static function string(string $name, ?string $default = null): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? $default;
        Assert::assertIsString($value, "the environment declares $name");

        return $value;
    }
}
