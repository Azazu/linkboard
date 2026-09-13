<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The DSNs the deep-probe tests need: the `app` database the prod kernel
 * reads (DATABASE_URL as is), the `_test` database of the suite, and Redis.
 */
final class ProbeTestEnvironment
{
    public static function appDatabaseUrl(): string
    {
        return self::env('DATABASE_URL');
    }

    /** DATABASE_URL with the suite's `_test` suffix (doctrine.yaml when@test dbname_suffix). */
    public static function testDatabaseUrl(): string
    {
        $url = self::appDatabaseUrl();
        $parts = parse_url($url);
        \assert(\is_array($parts) && isset($parts['path']));
        $suffix = '_test'.self::env('TEST_TOKEN', '');
        $path = $parts['path'].$suffix;

        return preg_replace('#'.preg_quote($parts['path'], '#').'(\?|$)#', $path.'$1', $url, 1) ?? throw new \RuntimeException('cannot derive the test database URL');
    }

    public static function redisUrl(): string
    {
        return self::env('REDIS_URL');
    }

    /** A DSN nothing listens on: the "database refuses connections" case. */
    public static function closedDatabaseUrl(): string
    {
        return 'postgresql://nobody:nothing@127.0.0.1:1/nowhere';
    }

    public static function closedRedisUrl(): string
    {
        return 'redis://127.0.0.1:1';
    }

    /** The URL with its host and port replaced (a proxy or a fixture in front of the server). */
    public static function withEndpoint(string $url, string $host, int $port): string
    {
        $parts = parse_url($url);
        \assert(\is_array($parts) && isset($parts['host']));
        $auth = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';

        return \sprintf('%s://%s%s:%d%s%s', $parts['scheme'] ?? 'postgresql', $auth, $host, $port, $parts['path'] ?? '', isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private static function env(string $name, ?string $default = null): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? $default;
        if (!\is_string($value)) {
            throw new \RuntimeException(\sprintf('%s is not set for the test process.', $name));
        }

        return $value;
    }
}
