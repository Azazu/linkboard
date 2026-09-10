<?php

declare(strict_types=1);

namespace App\Click\Counter;

use App\Click\RecordOutcome;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The Redis click counter (design decision 2): one EVAL of a fixed Lua script
 * per call — the script body is sent every time, never EVALSHA, so a flushed
 * script cache costs no second round trip. Atomically: lift an absent or lower
 * key to the persisted count (never lower it), compare with max_clicks,
 * increment only when allowed. Key `<prefix>link:<uuid>:clicks`, no TTL; the
 * prefix is '' in dev/prod and `test:` in the test environment so the suite
 * can clean its keys on a shared Redis. Guarantee boundary: exact while the key
 * exists; after a seed the redirects accepted but not yet persisted are not
 * in the seed (spec redirect, "Click limit is exact under concurrency").
 */
final class RedisClickCounter implements ClickCounterInterface
{
    public const string SCRIPT = <<<'LUA'
        local seed = tonumber(ARGV[1])
        local current = tonumber(redis.call('GET', KEYS[1]))
        if current == nil or current < seed then
            redis.call('SET', KEYS[1], seed)
            current = seed
        end
        if current >= tonumber(ARGV[2]) then
            return 0
        end
        redis.call('INCR', KEYS[1])
        return 1
        LUA;

    /** @var \Closure(string): \Redis */
    private \Closure $clientFactory;
    private ?\Redis $client = null;

    /**
     * @param (\Closure(string): \Redis)|null $clientFactory test seam; production builds the client from REDIS_URL
     */
    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
        #[Autowire(param: 'app.click_counter_key_prefix')]
        private readonly string $keyPrefix = '',
        ?\Closure $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory ?? static function (string $dsn): \Redis {
            $client = RedisAdapter::createConnection($dsn);
            if (!$client instanceof \Redis) {
                throw new \RuntimeException('REDIS_URL must point to a single Redis server (ext-redis client).');
            }

            return $client;
        };
    }

    public function increment(Uuid $linkId, int $seed, int $max): RecordOutcome
    {
        try {
            $reply = $this->client()->eval(self::SCRIPT, [$this->key($linkId), $seed, $max], 1);
        } catch (\RedisException $e) {
            throw new \RuntimeException('Click counter unavailable: '.$e::class, 0, $e);
        }

        return match ($reply) {
            1 => RecordOutcome::Allowed,
            0 => RecordOutcome::Exhausted,
            default => throw new \RuntimeException(\sprintf('Unexpected click counter reply: %s', var_export($reply, true))),
        };
    }

    public function forget(Uuid $linkId): void
    {
        try {
            $this->client()->del($this->key($linkId));
        } catch (\RedisException $e) {
            throw new \RuntimeException('Click counter unavailable: '.$e::class, 0, $e);
        }
    }

    public function key(Uuid $linkId): string
    {
        return $this->keyPrefix.'link:'.$linkId->toRfc4122().':clicks';
    }

    private function client(): \Redis
    {
        try {
            return $this->client ??= ($this->clientFactory)($this->redisUrl);
        } catch (\RedisException $e) {
            throw new \RuntimeException('Click counter unavailable: '.$e::class, 0, $e);
        }
    }
}
