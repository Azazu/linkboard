<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Click\Handler\ClickRecordedHandler;
use App\Click\Message\ClickRecorded;
use App\Click\Retention\ClickRetention;
use App\Shared\Db\Row;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec click-logging "Click messages are handled idempotently" and the handler
 * half of "Messages for deleted links are discarded" (design decision 4),
 * against PostgreSQL. The Worker lifecycle (ack, retry, park) is covered in
 * the Web suite.
 */
#[CoversClass(ClickRecordedHandler::class)]
#[CoversClass(ClickRetention::class)]
final class ClickRecordedHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private TestHandler $log;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
    }

    public function testOneMessageIsOneRowAndOneIncrement(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $message = self::message((string) $link->getId());

        $this->handler()($message);

        $rows = self::connection()->fetchAllAssociative('SELECT * FROM clicks WHERE link_id = ?', [$message->linkId]);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(['id', 'occurred_at', 'country', 'device_type', 'os', 'browser', 'is_bot', 'referer_host', 'visitor_hash', 'variant', 'resolved_by', 'link_id'], array_keys($row));
        self::assertSame($message->clickId, $row['id']);
        self::assertSame('2026-09-10 10:00:00+00', $row['occurred_at']);
        self::assertSame(['DE', 'smartphone', 'iOS', 'Mobile Safari', false, 'news.example.org', $message->visitorHash, 'B', 'variant'], [$row['country'], $row['device_type'], $row['os'], $row['browser'], $row['is_bot'], $row['referer_host'], $row['visitor_hash'], $row['variant'], $row['resolved_by']]);
        self::assertSame(1, self::clickCount($link->getId()));
    }

    public function testRedeliveryIsAcknowledgedWithoutASecondRowOrIncrement(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $message = self::message((string) $link->getId());
        $handler = $this->handler();

        $handler($message);
        $handler($message);

        self::assertSame(1, self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE id = ?', [$message->clickId]));
        self::assertSame(1, self::clickCount($link->getId()));
        self::assertCount(1, $this->log->getRecords());
        self::assertSame(Logger::DEBUG, $this->log->getRecords()[0]->level->value);
        self::assertSame($message->clickId, $this->log->getRecords()[0]->context['click_id']);
    }

    public function testAMessageForADeletedLinkIsDiscardedWithAnInfoRecord(): void
    {
        $gone = Uuid::v7();
        $message = self::message($gone->toRfc4122());

        $this->handler()($message); // no exception: the worker acknowledges

        self::assertSame(0, self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE id = ?', [$message->clickId]));
        self::assertCount(1, $this->log->getRecords());
        $record = $this->log->getRecords()[0];
        self::assertSame(Logger::INFO, $record->level->value);
        self::assertSame($gone->toRfc4122(), $record->context['link_id']);
        self::assertSame($message->clickId, $record->context['click_id']);
    }

    public function testAnyOtherFailureRollsBackAndPropagates(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $message = self::message((string) $link->getId(), visitorHash: str_repeat('f', 65)); // char(64): value too long

        try {
            $this->handler()($message);
            self::fail('a non-constraint failure must reach the retry strategy');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('too long', $e->getMessage());
        }

        self::assertSame(0, self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$message->linkId]));
        self::assertSame(0, self::clickCount($link->getId()), 'the increment was rolled back with the failed insert');
        self::assertCount(0, $this->log->getRecords());
    }

    public function testAMessageFromAnEarlierMonthIsRecordedInThatMonthsPartition(): void
    {
        // the redelivery guarantee now rests on (click_id, occurred_at): two
        // rows with one id would land in different partitions if it were lost
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $earlier = new \DateTimeImmutable('-2 months');
        $message = self::messageAt((string) $link->getId(), $earlier);

        $this->handler()($message);
        $this->handler()($message);

        self::assertSame(1, Row::toInt(self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$message->linkId])));
        self::assertSame(1, self::clickCount($link->getId()));
        self::assertSame(
            'clicks_'.$earlier->format('Y_m'),
            self::connection()->fetchOne('SELECT tableoid::regclass::text FROM clicks WHERE id = ?', [$message->clickId]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expiredArrivals(): iterable
    {
        yield 'a first delivery dated before the window' => ['-20 months'];
        yield 'a retry from the failed transport long after the fact' => ['-14 months'];
    }

    #[DataProvider('expiredArrivals')]
    public function testAMessageOlderThanTheBoundaryIsDiscardedNotParked(string $age): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $message = self::messageAt((string) $link->getId(), new \DateTimeImmutable($age));

        $this->handler()($message);

        self::assertSame(0, Row::toInt(self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$message->linkId])));
        self::assertSame(0, self::clickCount($link->getId()), 'no increment either');
        self::assertTrue($this->log->hasInfoThatContains('older than the retention boundary'));
        self::assertSame(['link_id' => $message->linkId, 'click_id' => $message->clickId], $this->log->getRecords()[0]->context);
    }

    public function testARedeliveryAfterTheRecordsMonthWasDroppedIsDiscarded(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $month = new \DateTimeImmutable('-3 months');
        $message = self::messageAt((string) $link->getId(), $month);
        $this->handler()($message);
        self::assertSame(1, self::clickCount($link->getId()));

        // retention removes that month and records how far it removed
        self::connection()->executeStatement('DROP TABLE clicks_'.$month->format('Y_m'));
        $retention = new ClickRetention(self::connection(), '13', '3');
        $retention->recordDroppedThrough(new \DateTimeImmutable('-2 months'));

        $this->handler($retention)($message);

        self::assertSame(0, Row::toInt(self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$message->linkId])));
        self::assertSame(1, self::clickCount($link->getId()), 'the counter keeps the one increment it earned');
        self::assertTrue($this->log->hasInfoThatContains('older than the retention boundary'));
    }

    public function testWideningTheWindowDoesNotResurrectADroppedClick(): void
    {
        // the demonstrated failing input for the durable boundary: with a guard
        // that knew only the window, this records the click a second time
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $month = new \DateTimeImmutable('-3 months');
        $message = self::messageAt((string) $link->getId(), $month);
        $this->handler()($message);

        // dropped under a short window, which records the boundary...
        self::connection()->executeStatement('DROP TABLE clicks_'.$month->format('Y_m'));
        new ClickRetention(self::connection(), '2', '3')->recordDroppedThrough(new \DateTimeImmutable('-2 months'));
        // ...and the window is then lengthened, so the month is provisioned again
        self::connection()->executeStatement('SELECT clicks_ensure_partition(?)', [$month->format('Y-m-01')]);

        $this->handler(new ClickRetention(self::connection(), '13', '3'))($message);

        self::assertSame(0, Row::toInt(self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$message->linkId])));
        self::assertSame(1, self::clickCount($link->getId()), 'the lifetime counter is not incremented twice');
    }

    public function testAClickInsideTheWindowWithNoPartitionIsRetriedNotSwallowed(): void
    {
        // neither of the handler's two exception guards covers this: it must
        // reach the transport's retry policy
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $month = new \DateTimeImmutable('-4 months');
        self::connection()->executeStatement('DROP TABLE clicks_'.$month->format('Y_m'));
        $message = self::messageAt((string) $link->getId(), $month);

        try {
            $this->handler()($message);
            self::fail('the missing partition was swallowed');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('no partition of relation', $e->getMessage());
        }

        self::assertSame(0, self::clickCount($link->getId()), 'the increment was rolled back');
        self::assertCount(0, $this->log->getRecords(), 'and nothing was logged as discarded');
    }

    public function testADeletedLinkWithNoPartitionIsRetriedThenDiscardedOnceThePartitionExists(): void
    {
        // the FK guard fires on the insert, so it cannot fire when there is no
        // partition to insert into
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $month = new \DateTimeImmutable('-4 months');
        $message = self::messageAt((string) $link->getId(), $month);
        self::connection()->executeStatement('DELETE FROM links WHERE id = ?', [$message->linkId]);
        self::connection()->executeStatement('DROP TABLE clicks_'.$month->format('Y_m'));

        try {
            $this->handler()($message);
            self::fail('the missing partition was swallowed');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('no partition of relation', $e->getMessage());
        }

        self::connection()->executeStatement('SELECT clicks_ensure_partition(?)', [$month->format('Y-m-01')]);
        $this->handler()($message);

        self::assertTrue($this->log->hasInfoThatContains('the link no longer exists'));
    }

    private static function messageAt(string $linkId, \DateTimeImmutable $at): ClickRecorded
    {
        $message = self::message($linkId);

        return new ClickRecorded(
            $message->clickId,
            $message->linkId,
            $at,
            $message->country,
            $message->deviceType,
            $message->os,
            $message->browser,
            $message->isBot,
            $message->resolvedBy,
            $message->variant,
            $message->refererHost,
            $message->visitorHash,
        );
    }

    public static function message(string $linkId, string $visitorHash = ''): ClickRecorded
    {
        return new ClickRecorded(
            Uuid::v7()->toRfc4122(),
            $linkId,
            new \DateTimeImmutable('2026-09-10T10:00:00+00:00'),
            'DE',
            'smartphone',
            'iOS',
            'Mobile Safari',
            false,
            'variant',
            'B',
            'news.example.org',
            '' === $visitorHash ? str_repeat('a', 64) : $visitorHash,
        );
    }

    private function handler(?ClickRetention $retention = null, ?ClockInterface $clock = null): ClickRecordedHandler
    {
        return new ClickRecordedHandler(
            self::connection(),
            new Logger('test', [$this->log]),
            $retention ?? self::retention(),
            $clock ?? new NativeClock(),
        );
    }

    private static function retention(string $months = '13', string $horizon = '3'): ClickRetention
    {
        return new ClickRetention(self::connection(), $months, $horizon);
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private static function clickCount(Uuid $linkId): int
    {
        return Row::toInt(self::connection()->fetchOne('SELECT click_count FROM links WHERE id = ?', [$linkId->toRfc4122()]));
    }
}
