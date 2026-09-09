<?php

declare(strict_types=1);

// Consumes one token of the Redis-backed `auth_ip_redis` limiter for the key
// given as argv[1] and prints A (accepted) or R (rejected). Spawned in
// parallel by RateLimiterConcurrencyTest to prove the lock makes the
// sliding window atomic across processes. With "--no-lock" as argv[2] the
// same storage is used without a lock (the failing input, recorded in the
// commit body, not asserted: the race is real but not deterministic).

use App\Kernel;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$key = $argv[1] ?? throw new InvalidArgumentException('key required');
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

if ('--no-lock' === ($argv[2] ?? null)) {
    $redisUrl = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'];
    $storage = new CacheStorage(new RedisAdapter(RedisAdapter::createConnection($redisUrl), 'rl_nolock'));
    $limiter = new SlidingWindowLimiter('auth_ip_redis-'.$key, (int) ($_SERVER['RATE_LIMIT_AUTH_PER_IP'] ?? 10), new DateInterval('PT1M'), $storage);
} else {
    $factory = $container->get('limiter.auth_ip_redis');
    assert($factory instanceof RateLimiterFactoryInterface);
    $limiter = $factory->create($key);
}

echo $limiter->consume()->isAccepted() ? 'A' : 'R';
