<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * The probe's ext-redis client factory: a connection whose connect and every
 * later read are bounded by one per-operation timeout, authenticated when the
 * URL carries a password. Raw ext-redis on purpose (as HealthProbe): the probe
 * must observe a Redis that is down without the application's cache pool.
 */
final class BoundedRedisCommands
{
    /**
     * @throws RedisOperationFailed naming `connect` or `auth`
     */
    public static function open(string $url, float $perOperationTimeout): \Redis
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'])) {
            throw new RedisOperationFailed('connect', new \InvalidArgumentException('REDIS_URL is not a parseable URL.'));
        }

        $redis = new \Redis();
        try {
            // connect timeout and read timeout (every later command) are both
            // bounded; name resolution happens before either and is the resolver's.
            $connected = $redis->connect($parts['host'], $parts['port'] ?? 6379, $perOperationTimeout, null, 0, $perOperationTimeout);
        } catch (\RedisException $e) {
            throw new RedisOperationFailed('connect', $e);
        }
        if (!$connected) {
            throw new RedisOperationFailed('connect', new \RedisException('connect() returned false'));
        }

        if (isset($parts['pass'])) {
            try {
                $authenticated = $redis->auth(rawurldecode($parts['pass']));
            } catch (\RedisException $e) {
                throw new RedisOperationFailed('auth', $e);
            }
            if (true !== $authenticated) {
                throw new RedisOperationFailed('auth', new \RedisException('AUTH refused'));
            }
        }

        return $redis;
    }

    public static function hasPassword(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts) && isset($parts['pass']);
    }
}
