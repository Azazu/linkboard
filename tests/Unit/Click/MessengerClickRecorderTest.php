<?php

declare(strict_types=1);

namespace App\Tests\Unit\Click;

use App\Auth\Entity\User;
use App\Click\ClickFacts;
use App\Click\Counter\ClickCounterInterface;
use App\Click\Message\ClickRecorded;
use App\Click\Recorder\MessengerClickRecorder;
use App\Click\RecordOutcome;
use App\Click\RefererHost;
use App\Click\Visit;
use App\Click\VisitorHasher;
use App\Link\Entity\Link;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Design decision 1 at the seam: counter only for limited links (its failure
 * propagates), one message with the finished facts and no personal data, a
 * dispatch failure absorbed with an error record.
 */
#[CoversClass(MessengerClickRecorder::class)]
#[CoversClass(ClickRecorded::class)]
final class MessengerClickRecorderTest extends TestCase
{
    private TestHandler $log;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->log = new TestHandler();
        $this->dispatched = [];
    }

    public function testUnlimitedLinkDispatchesTheFactsWithoutTheCounterOrPersonalData(): void
    {
        $counter = $this->createMock(ClickCounterInterface::class);
        $counter->expects(self::never())->method('increment');
        $link = $this->link();
        $facts = new ClickFacts('DE', 'smartphone', 'iOS', 'Mobile Safari', false, 'device', null);

        $outcome = $this->recorder($counter)->record($link, $this->visit(), $facts);

        self::assertSame(RecordOutcome::Allowed, $outcome);
        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0];
        self::assertInstanceOf(ClickRecorded::class, $message);
        self::assertSame((string) $link->getId(), $message->linkId);
        self::assertSame('0192b6f0-0000-7000-8000-000000000001', $message->clickId);
        self::assertSame(['DE', 'smartphone', 'iOS', 'Mobile Safari', false, 'device', null], [$message->country, $message->deviceType, $message->os, $message->browser, $message->isBot, $message->resolvedBy, $message->variant]);
        self::assertSame('news.example.org', $message->refererHost);
        self::assertSame((new VisitorHasher('salt'))->hash($this->visit()), $message->visitorHash);
        $serialized = serialize($message);
        self::assertStringNotContainsString('203.0.113.7', $serialized);
        self::assertStringNotContainsString('Probe/1.0', $serialized);
        self::assertStringNotContainsString('story?id=1', $serialized);
        self::assertCount(0, $this->log->getRecords());
    }

    public function testLimitedLinkConsultsTheCounterThenDispatches(): void
    {
        $link = $this->link(maxClicks: 3, clickCount: 2);
        $counter = $this->createMock(ClickCounterInterface::class);
        $counter->expects(self::once())->method('increment')->with($link->getId(), 2, 3)->willReturn(RecordOutcome::Allowed);

        self::assertSame(RecordOutcome::Allowed, $this->recorder($counter)->record($link, $this->visit(), ClickFacts::default()));
        self::assertCount(1, $this->dispatched);
    }

    public function testExhaustedCounterDispatchesNothing(): void
    {
        $counter = $this->createStub(ClickCounterInterface::class);
        $counter->method('increment')->willReturn(RecordOutcome::Exhausted);

        self::assertSame(RecordOutcome::Exhausted, $this->recorder($counter)->record($this->link(maxClicks: 3, clickCount: 3), $this->visit(), ClickFacts::default()));
        self::assertCount(0, $this->dispatched);
    }

    public function testCounterFailurePropagatesAndDispatchesNothing(): void
    {
        $counter = $this->createStub(ClickCounterInterface::class);
        $counter->method('increment')->willThrowException(new \RuntimeException('Click counter unavailable'));

        try {
            $this->recorder($counter)->record($this->link(maxClicks: 3), $this->visit(), ClickFacts::default());
            self::fail('the counter failure must reach the resolver (503 for a limited link)');
        } catch (\RuntimeException $e) {
            self::assertSame('Click counter unavailable', $e->getMessage());
        }
        self::assertCount(0, $this->dispatched);
        self::assertCount(0, $this->log->getRecords());
    }

    public function testDispatchFailureIsAbsorbedWithAnErrorRecord(): void
    {
        $counter = $this->createStub(ClickCounterInterface::class);
        $counter->method('increment')->willReturn(RecordOutcome::Allowed);
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new TransportException('stream unavailable'));
        $link = $this->link(maxClicks: 3);

        $outcome = $this->recorder($counter, $bus)->record($link, $this->visit(), ClickFacts::default());

        self::assertSame(RecordOutcome::Allowed, $outcome);
        self::assertCount(1, $this->log->getRecords());
        $record = $this->log->getRecords()[0];
        self::assertSame(Logger::ERROR, $record->level->value);
        self::assertSame((string) $link->getId(), $record->context['link_id']);
        self::assertSame(TransportException::class, $record->context['exception']);
        $json = json_encode($record->toArray(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('203.0.113.7', $json);
        self::assertStringNotContainsString('Probe/1.0', $json);
    }

    private function recorder(ClickCounterInterface $counter, ?MessageBusInterface $bus = null): MessengerClickRecorder
    {
        if (null === $bus) {
            $bus = $this->createStub(MessageBusInterface::class);
            $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
                $this->dispatched[] = $message;

                return new Envelope($message);
            });
        }

        return new MessengerClickRecorder($counter, $bus, new VisitorHasher('salt'), new RefererHost('http://localhost:8082'), new Logger('test', [$this->log]), static fn (): Uuid => Uuid::fromString('0192b6f0-0000-7000-8000-000000000001'));
    }

    private function link(?int $maxClicks = null, int $clickCount = 0): Link
    {
        $now = new \DateTimeImmutable();
        $link = new Link(new User('o@example.com', 'hash', $now), 's', 'https://example.com/t', $now);
        if (null !== $maxClicks) {
            $link->setClickLimit($maxClicks, $now);
        }
        if ($clickCount > 0) {
            new \ReflectionProperty(Link::class, 'clickCount')->setValue($link, $clickCount);
        }

        return $link;
    }

    private function visit(): Visit
    {
        return new Visit('203.0.113.7', 'Probe/1.0', 'https://News.Example.org/story?id=1', new \DateTimeImmutable('2026-09-10T10:00:00+00:00'));
    }
}
