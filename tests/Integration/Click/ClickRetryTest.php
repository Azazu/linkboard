<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Click\Message\ClickRecorded;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec click-logging "Failed messages are retried, then parked" through a real
 * Worker over the in-memory transports with the container's dispatcher (the
 * configured retry and failure listeners): four attempts, three retries with
 * the configured backoff (10/20/40 ms in the test environment, jitter 0), one
 * envelope parked on `failed`, click data unchanged.
 */
#[CoversNothing]
final class ClickRetryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    protected function tearDown(): void
    {
        foreach (['messenger.transport.async', 'messenger.transport.failed'] as $id) {
            $transport = self::getContainer()->get($id);
            if ($transport instanceof InMemoryTransport) {
                $transport->reset();
            }
        }
        parent::tearDown();
    }

    public function testAPersistentlyFailingMessageIsRetriedThreeTimesThenParked(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $poison = new ClickRecorded(Uuid::v7()->toRfc4122(), (string) $link->getId(), new \DateTimeImmutable(), null, null, null, null, false, 'default', null, null, str_repeat('f', 65)); // char(64): every attempt fails
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($poison);
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $failed = self::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(InMemoryTransport::class, $failed);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $attempts = 0;
        $retries = [];
        $count = static function () use (&$attempts): void { ++$attempts; };
        $collect = static function (WorkerMessageRetriedEvent $event) use (&$retries): void { $retries[] = $event->getEnvelope(); };
        $stop = new StopWorkerOnMessageLimitListener(4);
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, $count);
        $dispatcher->addListener(WorkerMessageRetriedEvent::class, $collect);
        $dispatcher->addSubscriber($stop);
        try {
            (new Worker(['async' => $async], $bus, $dispatcher))->run(['sleep' => 1000]);
        } finally {
            $dispatcher->removeListener(WorkerMessageReceivedEvent::class, $count);
            $dispatcher->removeListener(WorkerMessageRetriedEvent::class, $collect);
            $dispatcher->removeSubscriber($stop);
        }

        self::assertSame(4, $attempts, 'the first attempt and three retries');
        self::assertCount(3, $retries);
        self::assertSame([10, 20, 40], array_map(static fn (Envelope $e): int => $e->last(DelayStamp::class)?->getDelay() ?? -1, $retries), 'exact backoff: jitter 0');
        self::assertSame([1, 2, 3], array_map(static fn (Envelope $e): int => $e->last(RedeliveryStamp::class)?->getRetryCount() ?? -1, $retries));
        $parked = $failed->getSent();
        self::assertCount(1, $parked);
        self::assertSame($poison, $parked[0]->getMessage());
        self::assertSame(0, $parked[0]->last(RedeliveryStamp::class)?->getRetryCount(), 'the parking stamp; the retry count lives in the retry events');
        self::assertCount(3, $parked[0]->all(RedeliveryStamp::class) ? \array_slice($parked[0]->all(RedeliveryStamp::class), 0, -1) : [], 'three retry stamps precede the parking stamp');
        self::assertSame([], iterator_to_array($async->get(10), false), 'nothing left on async');
        self::assertSame(0, self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT count(*) FROM clicks WHERE id = ?', [$poison->clickId]));
        self::assertSame(0, (int) self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT click_count FROM links WHERE id = ?', [$link->getId()->toRfc4122()]));
    }
}
