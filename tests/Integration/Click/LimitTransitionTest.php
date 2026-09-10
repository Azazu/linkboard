<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Click\ClickFacts;
use App\Click\Counter\RedisClickCounter;
use App\Click\Handler\ClickRecordedHandler;
use App\Click\Message\ClickRecorded;
use App\Click\Recorder\MessengerClickRecorder;
use App\Click\RecordOutcome;
use App\Click\RefererHost;
use App\Click\Visit;
use App\Click\VisitorHasher;
use App\Link\Entity\Link;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec redirect "Limit changed while requests are in flight" as a controlled
 * interleaving through the recorder seam (design decision 2, snapshot
 * semantics): real Redis counter, real handler, PostgreSQL. The seam has no
 * persisted-count fast path, so the lift itself is observable here.
 */
#[CoversNothing]
final class LimitTransitionTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private \Redis $redis;
    private RedisClickCounter $counter;
    /** @var list<ClickRecorded> */
    private array $queue = [];
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        $url = (string) ($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? '');
        $redis = RedisAdapter::createConnection($url);
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->counter = new RedisClickCounter($url, 'test:');
        $this->queue = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            $this->redis->del($key);
        }
        parent::tearDown();
    }

    public function testRequestsInFlightAcrossALimitChangeCompleteUnderTheirSnapshot(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $id = $link->getId();
        $this->keys[] = $this->counter->key($id);
        $recorder = $this->recorder();

        // ten requests loaded the unlimited row and pause before recording
        $old = array_fill(0, 10, $link);
        self::assertNull($old[0]->getMaxClicks());

        // the limit is set and committed while they are paused
        self::connection()->executeStatement('UPDATE links SET max_clicks = 3 WHERE id = ?', [$id->toRfc4122()]);
        $fresh = $this->reload($id);
        self::assertSame(3, $fresh->getMaxClicks());

        // three new requests consume the allowance
        self::assertSame([RecordOutcome::Allowed, RecordOutcome::Allowed, RecordOutcome::Allowed, RecordOutcome::Exhausted], array_map(fn (): RecordOutcome => $recorder->record($fresh, $this->visit(), ClickFacts::default()), range(1, 4)));
        self::assertSame('3', $this->redis->get($this->counter->key($id)));

        // the paused requests resume with their unlimited snapshot: no counter, accepted
        foreach ($old as $snapshot) {
            self::assertSame(RecordOutcome::Allowed, $recorder->record($snapshot, $this->visit(), ClickFacts::default()));
        }
        self::assertSame('3', $this->redis->get($this->counter->key($id)), 'the unlimited snapshots never touched the counter');
        self::assertCount(13, $this->queue, 'thirteen accepted — the in-flight bound');

        // the worker persists all thirteen
        $handler = new ClickRecordedHandler(self::connection(), new Logger('test', [new TestHandler()]));
        foreach ($this->queue as $message) {
            $handler($message);
        }
        self::assertSame(13, (int) self::connection()->fetchOne('SELECT click_count FROM links WHERE id = ?', [$id->toRfc4122()]));

        // the next call lifts the key to the persisted count and is exhausted
        $refreshed = $this->reload($id);
        self::assertSame(13, $refreshed->getClickCount());
        self::assertSame(RecordOutcome::Exhausted, $recorder->record($refreshed, $this->visit(), ClickFacts::default()));
        self::assertSame('13', $this->redis->get($this->counter->key($id)));
    }

    public function testSnapshotsWithAHigherMaximumKeepIncrementingAfterALowering(): void
    {
        $link = LinkFactory::new()->limited(10)->create(['owner' => UserFactory::createOne()]);
        $id = $link->getId();
        $this->keys[] = $this->counter->key($id);
        $recorder = $this->recorder();
        $old = $link; // max 10, loaded before the change

        self::connection()->executeStatement('UPDATE links SET max_clicks = 3 WHERE id = ?', [$id->toRfc4122()]);
        $fresh = $this->reload($id);

        self::assertSame([RecordOutcome::Allowed, RecordOutcome::Allowed, RecordOutcome::Allowed, RecordOutcome::Exhausted], array_map(fn (): RecordOutcome => $recorder->record($fresh, $this->visit(), ClickFacts::default()), range(1, 4)));
        self::assertSame([RecordOutcome::Allowed, RecordOutcome::Allowed], array_map(fn (): RecordOutcome => $recorder->record($old, $this->visit(), ClickFacts::default()), range(1, 2)), 'the old snapshot still evaluates max 10');
        self::assertSame('5', $this->redis->get($this->counter->key($id)));
        self::assertSame(RecordOutcome::Exhausted, $recorder->record($fresh, $this->visit(), ClickFacts::default()));
    }

    private function recorder(): MessengerClickRecorder
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            self::assertInstanceOf(ClickRecorded::class, $message);
            $this->queue[] = $message;

            return new Envelope($message);
        });

        return new MessengerClickRecorder($this->counter, $bus, new VisitorHasher('salt'), new RefererHost('http://localhost:8082'), new Logger('test', [new TestHandler()]));
    }

    private function reload(Uuid $id): Link
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $link = $em->find(Link::class, $id);
        self::assertInstanceOf(Link::class, $link);

        return $link;
    }

    private function visit(): Visit
    {
        return new Visit('203.0.113.7', 'Probe/1.0', null, new \DateTimeImmutable());
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
