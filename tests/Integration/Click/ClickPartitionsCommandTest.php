<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Click\Handler\ClickRecordedHandler;
use App\Click\Retention\ClickPartitionsCommand;
use App\Click\Retention\ClickRetention;
use App\Shared\Db\Row;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\InterruptingStatement;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The only thing in this application that drops click data, and the thing that
 * makes a click storable at all (change stretch-partition-clicks, design
 * decisions 5, 5b and 6).
 *
 * The command is constructed here rather than run through the console so the
 * window and the horizon are explicit in each case: the point of most of these
 * is what a *particular* setting does.
 */
#[CoversClass(ClickPartitionsCommand::class)]
#[CoversClass(ClickRetention::class)]
final class ClickPartitionsCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testItCreatesTheWindowAndTheHorizonAndIsIdempotent(): void
    {
        $first = $this->maintain(months: '13', horizon: '3');

        self::assertSame(Command::SUCCESS, $first['status']);
        $months = $this->partitionMonths();
        self::assertContains(self::month('-13 months'), $months, 'the start of the retention window');
        self::assertContains(self::month('now'), $months);
        self::assertContains(self::month('+3 months'), $months, 'the horizon');

        $second = $this->maintain(months: '13', horizon: '3');
        self::assertSame(Command::SUCCESS, $second['status']);
        self::assertStringContainsString('nothing to create', $second['output']);
        self::assertSame($months, $this->partitionMonths(), 'a second run changes nothing');
    }

    public function testItRecreatesAMissingHistoricalPartition(): void
    {
        // the case a forward-only provisioning would fail: a month inside the
        // window, in the past, that something removed
        $month = self::month('-6 months');
        $this->connection()->executeStatement(\sprintf('DROP TABLE clicks_%s', $month));
        self::assertNotContains($month, $this->partitionMonths());

        $result = $this->maintain(months: '13', horizon: '3');

        self::assertContains($month, $this->partitionMonths());
        self::assertStringContainsString('clicks_'.$month, $result['output'], 'the run names what it created');
    }

    public function testRetentionDropsAMonthEntirelyOutsideTheWindowAndKeepsAStraddlingOne(): void
    {
        $link = $this->link();
        $old = new \DateTimeImmutable('-4 months');
        $straddling = new \DateTimeImmutable('-2 months');
        $this->click($link, $old->modify('first day of this month')->setTime(12, 0));
        $this->click($link, $straddling->modify('first day of this month')->setTime(12, 0));
        $this->click($link, new \DateTimeImmutable('-1 day'));

        // a window of 2 months puts the cutoff mid-month: the month it lands in
        // straddles the boundary and keeps its rows
        $result = $this->maintain(months: '2', horizon: '3', retention: true);

        self::assertSame(Command::SUCCESS, $result['status']);
        self::assertNotContains(self::month('-4 months'), $this->partitionMonths(), 'a month entirely before the cutoff is gone');
        self::assertStringContainsString('clicks_'.self::month('-4 months'), $result['output'], 'and is named');
        self::assertContains(self::month('-2 months'), $this->partitionMonths(), 'the month the cutoff falls in keeps its older rows');
        self::assertSame(2, $this->clickCount($link), 'only the clicks of the dropped month are gone');
    }

    public function testTheRecordedBoundaryIsTheEndOfTheLastMonthActuallyRemoved(): void
    {
        // not the configured cutoff: a two-month window on the 16th keeps the
        // month the cutoff falls in, so data was removed through the START of
        // that month, and a later first delivery from its surviving days must
        // still be recordable (Gate 2 round 1, finding 1)
        $link = $this->link();
        $this->click($link, new \DateTimeImmutable('-4 months'));

        $this->maintain(months: '2', horizon: '3', retention: true);

        $boundary = $this->retention('2', '3')->droppedThrough();
        self::assertNotNull($boundary, 'dropping records the point data was removed through');
        $expected = new \DateTimeImmutable(self::monthDate('-2 months'));
        self::assertSame(
            $expected->format('Y-m-d'),
            $boundary->format('Y-m-d'),
            'the end of the last removed month, which is the first day of the month that survived',
        );
    }

    public function testAClickInTheSurvivingStraddlingMonthIsStillRecordableAfterWidening(): void
    {
        $link = $this->link();
        $this->click($link, new \DateTimeImmutable('-4 months'));
        $this->maintain(months: '2', horizon: '3', retention: true);

        // the month the cutoff fell in was never removed, so a first delivery
        // from its days is inside the widened window and must be accepted
        $survivor = new \DateTimeImmutable(self::monthDate('-2 months'));
        self::assertFalse(
            $this->retention('13', '3')->isExpired($survivor->modify('+9 days'), new \DateTimeImmutable()),
            'a click from the month retention kept is not behind the boundary',
        );
    }

    public function testTheBoundaryNeverMovesBackwards(): void
    {
        $link = $this->link();
        $this->click($link, new \DateTimeImmutable('-4 months'));

        $this->maintain(months: '2', horizon: '3', retention: true);
        $boundary = $this->retention('2', '3')->droppedThrough();

        // a longer window later must not move the record backwards: a click
        // that is gone stays gone as far as the handler is concerned
        $this->maintain(months: '13', horizon: '3', retention: true);
        self::assertEquals($boundary, $this->retention('13', '3')->droppedThrough());
    }

    public function testTheRealMaintenanceCommandCannotDropWhileAHandlerHoldsItsDecision(): void
    {
        // The race in full, deterministically and through production code on
        // both sides (Gate 2 round 1, finding 2, and its confirmation): the
        // handler is stopped between its eligibility decision and its insert,
        // and the REAL maintenance command runs there, on its own connection,
        // with a lock timeout so "it waits" is observable.
        //
        // Removing either production lock — the handler's shared one or the
        // command's exclusive one — makes the command succeed at that moment
        // and this test fail.
        // the worker runs with the long window, maintenance with a short one —
        // the reviewer's scenario exactly: the handler judges the click
        // recordable and the command is about to decide it is not
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne(), 'slug' => 'raced']);
        $month = new \DateTimeImmutable('-4 months');
        $message = ClickRecordedHandlerTest::messageAt((string) $link->getId(), $month);

        $maintenance = DriverManager::getConnection($this->connection()->getParams());
        $maintenance->executeStatement("SET lock_timeout = '250ms'");
        $blocked = null;

        InterruptingStatement::interruptOn('INSERT INTO clicks', static function () use ($maintenance, &$blocked): void {
            // the handler is inside its transaction, past its decision, about
            // to write: the command must not be able to remove that month now
            try {
                new ClickPartitionsCommand($maintenance, new ClickRetention($maintenance, '2', '3'))(
                    new SymfonyStyle(new ArrayInput([]), new BufferedOutput()),
                    true,
                );
                $blocked = false;
            } catch (DriverException $e) {
                $blocked = str_contains(strtolower($e->getMessage()), 'lock timeout');
            }
        });

        try {
            $this->handler($this->retention('13', '3'))($message);
        } finally {
            InterruptingStatement::reset();
        }

        self::assertTrue($blocked, 'the maintenance command dropped a month while a handler held its decision');
        self::assertSame(1, $this->clickCount($link->getId()), 'the handler completed its own write');

        // and the second half: once the handler is done, the same command runs
        // for real — dropping the month and recording the boundary — a later
        // run re-provisions it under a longer window, and the replay of that
        // very message must not be counted again
        $this->maintain(months: '2', horizon: '3', retention: true);
        self::assertNotContains($month->format('Y_m'), $this->partitionMonths(), 'the month is gone once nobody holds the lock');
        $this->maintain(months: '13', horizon: '3', retention: true);
        self::assertContains($month->format('Y_m'), $this->partitionMonths(), 're-provisioned by the longer window');

        $this->handler(new ClickRetention($this->connection(), '13', '3'))($message);

        self::assertSame(0, $this->clickRowCount($link->getId()), 'the replay wrote no row');
        self::assertSame(1, $this->lifetimeCounter($link->getId()), 'and the lifetime counter stayed at one');
    }

    private function handler(?ClickRetention $retention = null): ClickRecordedHandler
    {
        return new ClickRecordedHandler(
            $this->connection(),
            new Logger('test', [new TestHandler()]),
            $retention ?? $this->retention('13', '3'),
            new NativeClock(),
        );
    }

    private function clickRowCount(Uuid $link): int
    {
        return Row::toInt($this->connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$link->toRfc4122()]));
    }

    private function lifetimeCounter(Uuid $link): int
    {
        return Row::toInt($this->connection()->fetchOne('SELECT click_count FROM links WHERE id = ?', [$link->toRfc4122()]));
    }

    public function testNothingIsDroppedWithoutTheRetentionOption(): void
    {
        $link = $this->link();
        $this->click($link, new \DateTimeImmutable('-4 months'));
        $before = $this->partitionMonths();

        $result = $this->maintain(months: '1', horizon: '3');

        self::assertSame(Command::SUCCESS, $result['status']);
        self::assertStringContainsString('no month was dropped', $result['output']);
        self::assertSame($before, $this->partitionMonths());
        self::assertSame(1, $this->clickCount($link));
        self::assertNull($this->retention('1', '3')->droppedThrough());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidSettings(): iterable
    {
        foreach (['0', '-1', '', 'soon', '1.5', ' 3'] as $value) {
            yield "retention months: '$value'" => [$value, '3', 'CLICK_RETENTION_MONTHS'];
            yield "horizon months: '$value'" => ['13', $value, 'CLICK_PARTITION_HORIZON_MONTHS'];
        }
    }

    #[DataProvider('invalidSettings')]
    public function testAnInvalidSettingChangesNothingAtAll(string $months, string $horizon, string $named): void
    {
        $link = $this->link();
        $this->click($link, new \DateTimeImmutable('-4 months'));
        $partitions = $this->partitionMonths();

        $result = $this->maintain(months: $months, horizon: $horizon, retention: true);

        self::assertSame(Command::FAILURE, $result['status']);
        self::assertStringContainsString($named, $result['output'], 'the message names the setting');
        self::assertSame($partitions, $this->partitionMonths(), 'no partition was created or dropped');
        self::assertSame(1, $this->clickCount($link), 'no row was removed');
        self::assertNull($this->retention('13', '3')->droppedThrough(), 'and nothing was recorded as removed');
    }

    public function testTheMigrationsInitialProvisioningUsesTheCommandsDefaults(): void
    {
        // two places hold these numbers — the migration cannot read the
        // environment — so this is what keeps them from drifting apart
        $migration = (string) file_get_contents(\dirname(__DIR__, 3).'/migrations/Version20260916120000.php');

        self::assertStringContainsString(\sprintf('months integer := %d;', ClickRetention::DEFAULT_MONTHS), $migration);
        self::assertStringContainsString(\sprintf('horizon integer := %d;', ClickRetention::DEFAULT_HORIZON_MONTHS), $migration);
    }

    /**
     * @return array{status: int, output: string}
     */
    private function maintain(string $months, string $horizon, bool $retention = false): array
    {
        $output = new BufferedOutput();
        $command = new ClickPartitionsCommand($this->connection(), $this->retention($months, $horizon));
        $status = $command(new SymfonyStyle(new ArrayInput([]), $output), $retention);

        return ['status' => $status, 'output' => $output->fetch()];
    }

    private function retention(string $months, string $horizon): ClickRetention
    {
        return new ClickRetention($this->connection(), $months, $horizon);
    }

    /**
     * @return list<string>
     */
    private function partitionMonths(): array
    {
        return array_map(
            static fn (string $name): string => substr($name, \strlen('clicks_')),
            Row::toStrings($this->connection()->fetchFirstColumn(<<<'SQL'
                SELECT child.relname
                FROM pg_class parent
                JOIN pg_inherits i ON i.inhparent = parent.oid
                JOIN pg_class child ON child.oid = i.inhrelid
                WHERE parent.relname = 'clicks'
                ORDER BY child.relname
                SQL), 'partition'),
        );
    }

    private static function monthDate(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier))->format('Y-m-01');
    }

    private static function month(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier))->format('Y_m');
    }

    private function link(): Uuid
    {
        $owner = UserFactory::createOne(['email' => 'owner@example.com']);

        return LinkFactory::createOne(['owner' => $owner, 'slug' => 'kept'])->getId();
    }

    private function click(Uuid $link, \DateTimeImmutable $at): void
    {
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO clicks (id, occurred_at, is_bot, visitor_hash, resolved_by, link_id)
                VALUES (gen_random_uuid(), :at, false, repeat('a', 64), 'default', :link)
                SQL,
            ['at' => $at->format(\DATE_ATOM), 'link' => $link->toRfc4122()],
        );
    }

    private function clickCount(Uuid $link): int
    {
        return Row::toInt($this->connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$link->toRfc4122()]));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
