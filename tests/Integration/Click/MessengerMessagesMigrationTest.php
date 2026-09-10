<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec click-logging "Migration is reversible" plus the upgrade path (Gate 2
 * finding 1): the migration creates messenger_messages on a fresh database,
 * adopts a table the previous auto_setup transport created — rows kept, the
 * bridge's index renamed, no duplicate index — and on rollback drops an empty
 * table but keeps one that still holds parked messages. Runs inside the
 * per-test transaction (PostgreSQL DDL is transactional).
 */
#[CoversNothing]
final class MessengerMessagesMigrationTest extends KernelTestCase
{
    use ResetDatabase;

    private const string VERSION = 'DoctrineMigrations\\Version20260910142101';

    public function testFreshDatabase(): void
    {
        $this->dropTable();
        self::assertFalse($this->tableExists());

        $this->execute('--up');

        self::assertTrue($this->tableExists());
        self::assertSame(['idx_messenger_messages_queue', 'messenger_messages_pkey'], $this->indexes());
        self::assertSame(['id', 'body', 'headers', 'queue_name', 'created_at', 'available_at', 'delivered_at'], $this->columns());

        $this->execute('--down');
        self::assertFalse($this->tableExists(), 'an empty table is dropped on rollback');
    }

    public function testUpgradeAdoptsTheAutoCreatedTableAndKeepsItsRows(): void
    {
        $this->dropTable();
        $transport = new TransportConnection(['table_name' => 'messenger_messages', 'queue_name' => 'failed', 'auto_setup' => true], $this->connection());
        $transport->setup(); // what the previous configuration did at runtime
        $transport->send('parked-body', ['type' => 'x']);
        self::assertTrue($this->tableExists());
        self::assertContains('idx_75ea56e0fb7336f0e3bd61ce16ba31dbbf396750', $this->indexes(), 'the bridge names its index');

        $this->execute('--up');

        self::assertSame(1, $this->rows(), 'the parked row survives the upgrade');
        self::assertSame(['idx_messenger_messages_queue', 'messenger_messages_pkey'], $this->indexes(), 'renamed, not duplicated');

        $this->execute('--down');
        self::assertTrue($this->tableExists(), 'a table with parked messages is kept on rollback');
        self::assertSame(1, $this->rows());

        $this->connection()->executeStatement('DELETE FROM messenger_messages');
        $this->execute('--down');
        self::assertFalse($this->tableExists(), 'once empty, the rollback drops it');

        $this->execute('--up');
        self::assertTrue($this->tableExists(), 'and the migration re-applies cleanly');
    }

    /** A fresh kernel per execution: a migration instance is frozen after it ran once in a process (as one console run does). */
    private function execute(string $direction): void
    {
        self::ensureKernelShutdown();
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => [self::VERSION], $direction => true, '--no-interaction' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    /** Back to the state before this migration: no table, no version row (all inside the per-test transaction). */
    private function dropTable(): void
    {
        $this->connection()->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        $this->connection()->executeStatement('DELETE FROM doctrine_migration_versions WHERE version = ?', [self::VERSION]);
    }

    private function tableExists(): bool
    {
        return null !== $this->connection()->fetchOne("SELECT to_regclass('messenger_messages')");
    }

    /** @return list<string> */
    private function indexes(): array
    {
        $names = $this->connection()->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE tablename = 'messenger_messages' ORDER BY indexname");

        return array_map(strval(...), $names);
    }

    /** @return list<string> */
    private function columns(): array
    {
        return array_map(strval(...), $this->connection()->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'messenger_messages' ORDER BY ordinal_position"));
    }

    private function rows(): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM messenger_messages');
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
