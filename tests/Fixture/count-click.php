<?php

declare(strict_types=1);

// Increments the click counter of the link id in argv[1] once (seed 0, max 3)
// and prints A (allowed) or E (exhausted). Spawned in parallel by
// RedisClickCounterTest to prove the Lua script keeps the limit exact across
// processes racing on an absent key (design decision 2). Test key prefix.

use App\Click\Counter\RedisClickCounter;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$linkId = Uuid::fromString($argv[1] ?? throw new InvalidArgumentException('link id required'));
$redisUrl = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? throw new RuntimeException('REDIS_URL missing');
$counter = new RedisClickCounter($redisUrl, 'test:');

echo $counter->increment($linkId, 0, 3)->name[0];
