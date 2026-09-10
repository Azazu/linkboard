<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Click\ClickRecorderInterface;
use App\Click\Recorder\DbalClickRecorder;
use App\Click\RecordOutcome;
use App\Click\Visit;
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
 * what a stub proves is the controller's and resolver's reaction to that
 * failure, not behaviour during a real outage (design decisions 5, 9, 13).
 */
#[CoversNothing]
final class RedirectDegradationTest extends RedirectWebTestCase
{
    private TestHandler $log;

    public function testClickWriteFailureOnAnUnlimitedLinkStillRedirects(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'unlimited', 'targetUrl' => 'https://example.com/u']);
        $this->failRecorder();
        $this->captureLog();

        self::visit($client, '/unlimited');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/u');
        self::assertCount(0, self::clicksOf($link->getId()));
        self::assertTrue($this->log->hasErrorThatContains('Click not recorded'));
        $record = $this->log->getRecords()[array_key_last($this->log->getRecords())];
        self::assertSame((string) $link->getId(), $record->context['link_id']);
        self::assertSame(\RuntimeException::class, $record->context['exception']);
        $this->assertNoPersonalData();
    }

    public function testClickWriteFailureOnALimitedLinkIsUnavailable(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        LinkFactory::new()->limited(10)->create(['slug' => 'limited']);
        $this->failRecorder();
        $this->captureLog();

        self::visit($client, '/limited');

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '5');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertTrue($this->log->hasWarningThatContains('Click limit could not be enforced'));
        $this->assertNoPersonalData();

        self::visit($client, '/limited', ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(503);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
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

    private function failRecorder(): void
    {
        // the concrete class is final and cannot be doubled; the container id is replaced by a throwing implementation
        self::getContainer()->set(DbalClickRecorder::class, new class implements ClickRecorderInterface {
            public function record(Link $link, Visit $visit): RecordOutcome
            {
                throw new \RuntimeException('click store down');
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
