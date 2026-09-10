<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Click\ClickRecorderInterface;
use App\Click\Recorder\DbalClickRecorder;
use App\Click\RecordOutcome;
use App\Click\RefererHost;
use App\Click\Visit;
use App\Click\VisitorHasher;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Design decision 3: the conditional UPDATE is the check-and-increment of the
 * click limit and shares one transaction with the INSERT. Cases (a)–(c) run
 * inside the per-test transaction (the recorder's own transaction becomes a
 * savepoint). Case (d) needs real concurrency: rows are created on an
 * independent, autocommitting connection so that ten child processes
 * (tests/Fixture/record-click.php) can see the link and race on it; the test
 * deletes the rows it committed.
 */
#[CoversClass(DbalClickRecorder::class)]
#[CoversClass(RecordOutcome::class)]
final class DbalClickRecorderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testUnlimitedLinkIsCountedAndStored(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $now = new \DateTimeImmutable('2026-09-09T10:00:00+00:00');

        $outcome = self::recorder()->record($link, new Visit('203.0.113.7', 'Probe/1.0', 'https://News.Example.org/story?id=1', $now));

        self::assertSame(RecordOutcome::Allowed, $outcome);
        $rows = self::connection()->fetchAllAssociative('SELECT * FROM clicks WHERE link_id = ?', [$link->getId()->toRfc4122()]);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(['id', 'occurred_at', 'country', 'device_type', 'os', 'browser', 'is_bot', 'referer_host', 'visitor_hash', 'variant', 'resolved_by', 'link_id'], array_keys($row));
        self::assertTrue(Uuid::isValid((string) $row['id']));
        self::assertSame('2026-09-09 10:00:00+00', $row['occurred_at']);
        self::assertSame('news.example.org', $row['referer_host']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', (string) $row['visitor_hash']);
        self::assertFalse($row['is_bot']);
        self::assertSame('default', $row['resolved_by']);
        self::assertNull($row['country']);
        self::assertNull($row['variant']);
        self::assertStringNotContainsString('203.0.113.7', json_encode($row, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Probe/1.0', json_encode($row, \JSON_THROW_ON_ERROR));
        self::assertSame(1, self::clickCount($link->getId()));
    }

    public function testExhaustedLinkStoresNothing(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $link->setClickLimit(1, new \DateTimeImmutable());
        self::connection()->executeStatement('UPDATE links SET max_clicks = 1, click_count = 1 WHERE id = ?', [$link->getId()->toRfc4122()]);

        $outcome = self::recorder()->record($link, new Visit('203.0.113.7', 'Probe/1.0', null, new \DateTimeImmutable()));

        self::assertSame(RecordOutcome::Exhausted, $outcome);
        self::assertSame(0, self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$link->getId()->toRfc4122()]));
        self::assertSame(1, self::clickCount($link->getId()));
    }

    public function testIncrementAndInsertAreOneTransaction(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);
        $connection = self::connection();
        $fixedId = Uuid::v7();
        // a click with the id the recorder is about to use → the INSERT must fail
        $connection->insert('clicks', ['id' => $fixedId->toRfc4122(), 'link_id' => $link->getId()->toRfc4122(), 'occurred_at' => '2026-09-09 09:00:00+00', 'is_bot' => 0, 'visitor_hash' => str_repeat('0', 64), 'resolved_by' => 'default']);
        $recorder = new DbalClickRecorder($connection, new VisitorHasher('s'), new RefererHost('http://localhost:8082'), static fn (): Uuid => $fixedId);

        try {
            $recorder->record($link, new Visit('203.0.113.7', 'Probe/1.0', null, new \DateTimeImmutable()));
            self::fail('the duplicate click id must make the INSERT fail');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame(0, self::clickCount($link->getId()), 'the UPDATE was rolled back together with the failed INSERT');
        self::assertSame(1, $connection->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$link->getId()->toRfc4122()]));
    }

    public function testConcurrentRecordingsNeverPassTheLimit(): void
    {
        $params = self::connection()->getParams();
        $raw = DriverManager::getConnection($params); // outside the per-test transaction: committed rows, autocommit
        $userId = Uuid::v7()->toRfc4122();
        $linkId = Uuid::v7()->toRfc4122();
        $slug = 'race'.bin2hex(random_bytes(4));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:sP');
        $raw->insert('users', ['id' => $userId, 'email' => $slug.'@example.com', 'password_hash' => 'x', 'roles' => '["ROLE_USER"]', 'is_blocked' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $raw->insert('links', ['id' => $linkId, 'owner_id' => $userId, 'slug' => $slug, 'target_url' => 'https://example.com/race', 'max_clicks' => 3, 'click_count' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);

        try {
            $script = \dirname(__DIR__, 3).'/tests/Fixture/record-click.php';
            $processes = [];
            for ($i = 0; $i < 10; ++$i) {
                $process = new Process(['php', $script, $slug], \dirname(__DIR__, 3));
                $process->start();
                $processes[] = $process;
            }
            $allowed = 0;
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $allowed += substr_count($process->getOutput(), 'A');
            }

            self::assertSame(3, $allowed, 'exactly max_clicks recordings may win');
            self::assertSame(3, $raw->fetchOne('SELECT click_count FROM links WHERE id = ?', [$linkId]));
            self::assertSame(3, $raw->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$linkId]));
        } finally {
            $raw->executeStatement('DELETE FROM users WHERE id = ?', [$userId]); // cascades to the link and its clicks
            $raw->close();
        }
    }

    /**
     * Built by hand on the container's connection: the interface alias is
     * private and only resolvable through its consumer (the redirect resolver);
     * the wiring itself is covered by the Web suite and `debug:container`.
     */
    private static function recorder(): ClickRecorderInterface
    {
        return new DbalClickRecorder(self::connection(), new VisitorHasher('test-only-visitor-salt'), new RefererHost('http://localhost:8082'));
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
