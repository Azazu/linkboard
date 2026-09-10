<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Click\Counter\ClickCounterInterface;
use App\Click\Counter\RedisClickCounter;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Uid\Uuid;

/**
 * Spec links: "Delete a link" (incl. "Counter removed"); spec click-logging
 * "Redis down at deletion".
 */
#[CoversNothing]
final class DeleteLinkTest extends LinkApiTestCase
{
    public function testDeleteThenReuseTheSlug(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a, 'slug' => 'sale']);
        $token = $this->token($client, 'a@example.com');
        $id = (string) $link->getId();

        $this->api($client, $token, 'DELETE', "/api/v1/links/$id");
        self::assertResponseStatusCodeSame(204);

        $this->api($client, $token, 'GET', "/api/v1/links/$id");
        self::assertResponseStatusCodeSame(404);
        $this->api($client, $token, 'DELETE', "/api/v1/links/$id");
        self::assertResponseStatusCodeSame(404);

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => 'https://e.com', 'slug' => 'sale']);
        self::assertResponseStatusCodeSame(201);
    }

    public function testTheCounterKeyIsRemovedWithTheLink(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::new()->limited(5)->create(['owner' => $a, 'slug' => 'counted']);
        $counter = self::getContainer()->get(RedisClickCounter::class);
        self::assertInstanceOf(RedisClickCounter::class, $counter);
        $counter->increment($link->getId(), 0, 5); // the key exists, as after a redirect
        $redis = RedisAdapter::createConnection((string) ($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL']));
        self::assertInstanceOf(\Redis::class, $redis);
        self::assertSame('1', $redis->get($counter->key($link->getId())));
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'DELETE', '/api/v1/links/'.$link->getId());

        self::assertResponseStatusCodeSame(204);
        self::assertFalse($redis->get($counter->key($link->getId())));
    }

    public function testRedisDownAtDeletionStillDeletesAndWarns(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::new()->limited(5)->create(['owner' => $a, 'slug' => 'orphan-key']);
        self::getContainer()->set(RedisClickCounter::class, new class implements ClickCounterInterface {
            public function increment(Uuid $linkId, int $seed, int $max): \App\Click\RecordOutcome
            {
                throw new \RuntimeException('Click counter unavailable');
            }

            public function forget(Uuid $linkId): void
            {
                throw new \RuntimeException('Click counter unavailable');
            }
        });
        $log = new TestHandler();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($log);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'DELETE', '/api/v1/links/'.$link->getId());

        self::assertResponseStatusCodeSame(204);
        $this->api($client, $token, 'GET', '/api/v1/links/'.$link->getId());
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($log->hasWarningThatContains('Click counter key not removed'));
        $warning = array_values(array_filter($log->getRecords(), static fn ($r): bool => Logger::WARNING === $r->level->value))[0];
        self::assertSame((string) $link->getId(), $warning->context['link_id']);
        self::assertSame(\RuntimeException::class, $warning->context['exception']);
    }
}
