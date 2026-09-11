<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Click\Counter\RedisClickCounter;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Clicks are written by the worker, not by the request: `clicksOf()` and
 * `clickCountOf()` consume the in-memory `async` transport first (through a
 * real Messenger Worker with the container's dispatcher — the
 * `messenger:consume` path, so acknowledgement, retries and parking are what
 * production would do); the `raw*` variants read the database as it is. The
 * click counter uses the real Redis of REDIS_URL with the `test:` prefix; its
 * keys are removed after every test. Every helper disables the client's kernel
 * reboot: the in-memory transport lives in the container, and a rebooted
 * kernel would drop the messages of earlier requests (in production the Redis
 * stream outlives the request).
 */
abstract class RedirectWebTestCase extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            foreach (['messenger.transport.async', 'messenger.transport.failed'] as $id) {
                $transport = self::getContainer()->get($id); // a test may have replaced it by a failing stub
                if ($transport instanceof InMemoryTransport) {
                    $transport->reset();
                }
            }
            $redis = self::redis();
            $iterator = null;
            do {
                $keys = $redis->scan($iterator, 'test:link:*:clicks', 500);
                if (\is_array($keys) && [] !== $keys) {
                    $redis->del($keys);
                }
            } while (null !== $iterator && 0 !== (int) $iterator);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string> $server
     */
    protected static function visit(KernelBrowser $client, string $path, array $server = [], string $method = 'GET'): void
    {
        $client->disableReboot();
        $client->request($method, $path, server: $server + ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Probe/1.0']);
    }

    /**
     * Runs a real Worker over the in-memory async transport until `$limit`
     * messages were handled (default: everything currently pending, including
     * retries that become available while it runs). Returns that limit; 0 when
     * nothing was pending.
     */
    protected static function consumeAsync(?int $limit = null): int
    {
        $transport = self::asyncTransport();
        $limit ??= \count(self::pendingMessages());
        if (0 === $limit) {
            return 0;
        }
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $stop = new StopWorkerOnMessageLimitListener($limit);
        $dispatcher->addSubscriber($stop);
        try {
            (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 1000]);
        } finally {
            $dispatcher->removeSubscriber($stop);
        }

        return $limit;
    }

    /** @return list<Envelope> */
    protected static function pendingMessages(): array
    {
        return array_values(iterator_to_array(self::asyncTransport()->get(1000), false));
    }

    /** @return list<Envelope> */
    protected static function acknowledgedMessages(): array
    {
        return array_values(self::asyncTransport()->getAcknowledged());
    }

    /** @return list<Envelope> */
    protected static function rejectedMessages(): array
    {
        return array_values(self::asyncTransport()->getRejected());
    }

    /** @return list<Envelope> */
    protected static function failedMessages(): array
    {
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $failed);

        return array_values($failed->getSent());
    }

    protected static function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    protected static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * The click rows once the worker has run.
     *
     * @return list<array<string, mixed>>
     */
    protected static function clicksOf(Uuid $linkId): array
    {
        self::consumeAsync();

        return self::rawClicksOf($linkId);
    }

    /**
     * The click rows as they are, without consuming the transport.
     *
     * @return list<array<string, mixed>>
     */
    protected static function rawClicksOf(Uuid $linkId): array
    {
        return self::connection()->fetchAllAssociative('SELECT * FROM clicks WHERE link_id = ? ORDER BY occurred_at, id', [$linkId->toRfc4122()]);
    }

    /** The persisted count once the worker has run. */
    protected static function clickCountOf(Uuid $linkId): int
    {
        self::consumeAsync();

        return self::rawClickCountOf($linkId);
    }

    protected static function rawClickCountOf(Uuid $linkId): int
    {
        return (int) self::connection()->fetchOne('SELECT click_count FROM links WHERE id = ?', [$linkId->toRfc4122()]);
    }

    protected static function redis(): \Redis
    {
        $redis = RedisAdapter::createConnection((string) ($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? ''));
        self::assertInstanceOf(\Redis::class, $redis);

        return $redis;
    }

    /** A fresh counter on the test prefix — independent of the container, so a test may replace the service. */
    protected static function counter(): RedisClickCounter
    {
        return new RedisClickCounter((string) ($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? ''), self::counterPrefix());
    }

    protected static function counterPrefix(): string
    {
        $prefix = self::getContainer()->getParameter('app.click_counter_key_prefix');
        self::assertIsString($prefix);

        return $prefix;
    }

    protected static function counterKey(Uuid $linkId): string
    {
        return self::counter()->key($linkId);
    }

    /** The counter key's value, or null when the key does not exist. */
    protected static function counterValue(Uuid $linkId): ?string
    {
        $value = self::redis()->get(self::counterKey($linkId));

        return \is_string($value) ? $value : null;
    }

    protected function token(KernelBrowser $client, string $email): string
    {
        $client->disableReboot();
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => $email, 'password' => UserFactory::PASSWORD]);
        $token = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /** @return array<string, mixed> */
    protected function apiGet(KernelBrowser $client, string $token, string $uri): array
    {
        $client->disableReboot();
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /** @param array<string, mixed> $body */
    protected function apiPatch(KernelBrowser $client, string $token, string $uri, array $body): void
    {
        $client->disableReboot();
        $client->request('PATCH', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/merge-patch+json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
    }
}
