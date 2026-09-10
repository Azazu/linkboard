<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Click\Counter\ClickCounterInterface;
use App\Click\Counter\RedisClickCounter;
use App\Click\RecordOutcome;
use App\Link\Entity\Link;
use App\Link\LinkListQuery;
use App\Link\LinkRepositoryInterface;
use App\Link\Repository\DoctrineLinkRepository;
use App\Tests\Factory\LinkFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Spec redirect, "Failures of the stores" and "Limiter store unavailable".
 * Each failing dependency is a stub installed in the test container before
 * the first request (disableReboot keeps that container for the whole test);
 * what a stub proves is the controller's, resolver's and recorder's reaction
 * to that failure, not behaviour during a real outage. Since
 * add-async-click-logging the click write is a counter (limited links) and a
 * message: the counter's failure is the resolver's 503, the transport's
 * failure is absorbed by the recorder.
 */
#[CoversNothing]
final class RedirectDegradationTest extends RedirectWebTestCase
{
    private TestHandler $log;

    public function testTransportDownStillRedirectsAnUnlimitedLinkAndLogsAnError(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'unlimited', 'targetUrl' => 'https://example.com/u']);
        $this->failTransport();
        $this->captureLog();

        self::visit($client, '/unlimited');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/u');
        self::assertCount(0, self::rawClicksOf($link->getId()));
        self::assertTrue($this->log->hasErrorThatContains('Click message not dispatched'));
        $record = $this->log->getRecords()[array_key_last($this->log->getRecords())];
        self::assertSame((string) $link->getId(), $record->context['link_id']);
        self::assertSame(TransportException::class, $record->context['exception']);
        $this->assertNoPersonalData();
    }

    public function testCounterFailureOnALimitedLinkIsUnavailable(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        LinkFactory::new()->limited(10)->create(['slug' => 'limited']);
        $this->failCounter();
        $this->captureLog();

        self::visit($client, '/limited');

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '5');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertTrue($this->log->hasWarningThatContains('Click limit could not be enforced'));
        self::assertSame([], self::pendingMessages(), 'nothing is dispatched when the limit cannot be guaranteed');
        $this->assertNoPersonalData();

        self::visit($client, '/limited', ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(503);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testCounterFailureDoesNotAffectAnUnlimitedLink(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'free', 'targetUrl' => 'https://example.com/f']);
        $this->failCounter();
        $this->captureLog();

        self::visit($client, '/free');

        self::assertResponseStatusCodeSame(302);
        self::assertCount(1, self::pendingMessages());
        self::assertCount(1, self::clicksOf($link->getId()));
        self::assertFalse($this->log->hasWarningRecords());
        self::assertFalse($this->log->hasErrorRecords());
    }

    public function testLinkLookupFailureIsUnavailableForAnySlug(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        // the concrete class is final and cannot be doubled; the container id is replaced by a throwing implementation
        self::getContainer()->set(DoctrineLinkRepository::class, new class implements LinkRepositoryInterface {
            public function findById(Uuid $id): ?Link
            {
                throw new \RuntimeException('connection refused');
            }

            public function findBySlug(string $slug): ?Link
            {
                throw new \RuntimeException('connection refused');
            }

            public function slugExists(string $slug): bool
            {
                throw new \RuntimeException('connection refused');
            }

            public function add(Link $link): void
            {
                throw new \RuntimeException('connection refused');
            }

            public function remove(Link $link): void
            {
                throw new \RuntimeException('connection refused');
            }

            public function findPageForOwner(Uuid $ownerId, LinkListQuery $query, int $offset, int $limit): array
            {
                throw new \RuntimeException('connection refused');
            }

            public function countForOwner(Uuid $ownerId, LinkListQuery $query): int
            {
                throw new \RuntimeException('connection refused');
            }

            public function findPage(LinkListQuery $query, int $offset, int $limit): array
            {
                throw new \RuntimeException('connection refused');
            }

            public function count(LinkListQuery $query): int
            {
                throw new \RuntimeException('connection refused');
            }
        });
        $this->captureLog();

        self::visit($client, '/secret-slug');

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '5');
        self::assertTrue($this->log->hasErrorThatContains('Link lookup failed'));
        self::assertStringNotContainsString('secret-slug', $this->logJson());
    }

    public function testLimiterFactoryFailureFailsOpen(): void
    {
        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willThrowException(new \RuntimeException('redis down'));

        $this->assertFailsOpen($factory);
    }

    public function testLockAcquisitionFailureInsideConsumeFailsOpen(): void
    {
        $store = new class implements PersistingStoreInterface {
            public function save(Key $key): void
            {
                throw new \RuntimeException('lock store down');
            }

            public function delete(Key $key): void
            {
            }

            public function exists(Key $key): bool
            {
                return false;
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
            }
        };
        $limiter = new SlidingWindowLimiter('redirect_ip-test', 60, new \DateInterval('PT1M'), new InMemoryStorage(), new LockFactory($store)->createLock('redirect_ip-test'));

        $this->assertFailsOpen(self::factoryReturning($limiter));
    }

    public function testStorageFailureInsideConsumeFailsOpen(): void
    {
        $storage = new class implements StorageInterface {
            public function save(LimiterStateInterface $limiterState): void
            {
                throw new \RuntimeException('storage down');
            }

            public function fetch(string $limiterStateId): ?LimiterStateInterface
            {
                throw new \RuntimeException('storage down');
            }

            public function delete(string $limiterStateId): void
            {
            }
        };
        $limiter = new SlidingWindowLimiter('redirect_ip-test', 60, new \DateInterval('PT1M'), $storage);

        $this->assertFailsOpen(self::factoryReturning($limiter));
    }

    private function assertFailsOpen(RateLimiterFactoryInterface $factory): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'open', 'targetUrl' => 'https://example.com/o']);
        self::getContainer()->set('limiter.redirect_ip', $factory);
        $this->captureLog();

        self::visit($client, '/open');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/o');
        self::assertCount(1, self::clicksOf($link->getId()), 'the redirect proceeded normally, click included');
        self::assertTrue($this->log->hasWarningThatContains('Redirect rate limiter unavailable'));
        $record = $this->log->getRecords()[array_key_last($this->log->getRecords())];
        self::assertSame(['exception'], array_keys($record->context));
        $this->assertNoPersonalData();
    }

    private static function factoryReturning(LimiterInterface $limiter): RateLimiterFactoryInterface
    {
        return new class($limiter) implements RateLimiterFactoryInterface {
            public function __construct(private readonly LimiterInterface $limiter)
            {
            }

            public function create(?string $key = null): LimiterInterface
            {
                return $this->limiter;
            }
        };
    }

    private function failCounter(): void
    {
        // the concrete class is final and cannot be doubled; the container id is replaced by a throwing implementation
        self::getContainer()->set(RedisClickCounter::class, new class implements ClickCounterInterface {
            public function increment(Uuid $linkId, int $seed, int $max): RecordOutcome
            {
                throw new \RuntimeException('Click counter unavailable');
            }

            public function forget(Uuid $linkId): void
            {
                throw new \RuntimeException('Click counter unavailable');
            }
        });
    }

    private function failTransport(): void
    {
        self::getContainer()->set('messenger.transport.async', new class implements TransportInterface {
            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }

            public function send(Envelope $envelope): Envelope
            {
                throw new TransportException('stream unavailable');
            }
        });
    }

    private function captureLog(): void
    {
        $this->log = new TestHandler();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->log);
    }

    private function logJson(): string
    {
        return json_encode(array_map(static fn ($r) => $r->toArray(), $this->log->getRecords()), \JSON_THROW_ON_ERROR);
    }

    private function assertNoPersonalData(): void
    {
        $json = $this->logJson();
        self::assertStringNotContainsString('203.0.113.7', $json);
        self::assertStringNotContainsString('Probe/1.0', $json);
    }
}
