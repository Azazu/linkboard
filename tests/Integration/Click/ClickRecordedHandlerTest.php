<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Click\Handler\ClickRecordedHandler;
use App\Click\Message\ClickRecorded;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

    private function handler(): ClickRecordedHandler
    {
        return new ClickRecordedHandler(self::connection(), new Logger('test', [$this->log]));
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private static function clickCount(Uuid $linkId): int
    {
        return (int) self::connection()->fetchOne('SELECT click_count FROM links WHERE id = ?', [$linkId->toRfc4122()]);
    }
}
