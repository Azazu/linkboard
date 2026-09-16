<?php

declare(strict_types=1);

namespace App\Click\Retention;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps the click table's months in order: creates every partition a click may
 * legitimately fall in, and — only when asked — drops the months that are
 * entirely outside the retention window (change stretch-partition-clicks,
 * design decisions 5 and 6).
 *
 * Nothing else in the application drops click data. This command is the only
 * place that does, and it validates its configuration before issuing a single
 * statement that changes the schema: a window of `0` would place the cutoff at
 * this instant and make the current, populated month eligible.
 */
#[AsCommand(
    name: 'app:clicks:partitions',
    description: 'Create the click partitions ahead of time and, with --retention, drop the months outside the retention window',
)]
final class ClickPartitionsCommand
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ClickRetention $retention,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Also drop every month entirely outside the retention window')] bool $retention = false,
    ): int {
        // before any DDL: an invalid setting must leave the schema untouched
        try {
            $months = $this->retention->months();
            $horizon = $this->retention->horizonMonths();
        } catch (InvalidRetentionSetting $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $now = new \DateTimeImmutable();
        $created = $this->ensurePartitions($now, $months, $horizon);
        if ([] === $created) {
            $io->writeln('Partitions: nothing to create.');
        } else {
            $io->writeln(\sprintf('Partitions created: %s.', implode(', ', $created)));
        }

        if (!$retention) {
            $io->success(\sprintf('The next %d month(s) are provisioned; no month was dropped (pass --retention to drop).', $horizon));

            return Command::SUCCESS;
        }

        $cutoff = $now->modify(\sprintf('-%d months', $months));
        $dropped = $this->dropExpired($cutoff);
        if ([] === $dropped) {
            $io->success(\sprintf('Nothing to drop: no month lies entirely before %s.', $cutoff->format(\DATE_ATOM)));

            return Command::SUCCESS;
        }

        $io->success(\sprintf(
            'Dropped: %s. Click data is now removed through %s.',
            implode(', ', $dropped),
            ($this->retention->droppedThrough() ?? $cutoff)->format(\DATE_ATOM),
        ));

        return Command::SUCCESS;
    }

    /**
     * Every month from the start of the retention window to the horizon, plus
     * any month a record already occupies — the range that makes "a click
     * inside the window is storable" true of a freshly migrated database.
     *
     * @return list<string> the partitions this run created, in order
     */
    private function ensurePartitions(\DateTimeImmutable $now, int $months, int $horizon): array
    {
        $first = $this->firstMonth($now, $months);
        $last = $this->lastMonth($now, $horizon);

        $created = [];
        for ($month = $first; $month <= $last; $month = $month->modify('+1 month')) {
            $wasCreated = $this->connection->fetchOne('SELECT clicks_ensure_partition(:month)', ['month' => $month->format('Y-m-d')]);
            if (true === $wasCreated || 't' === $wasCreated || '1' === $wasCreated) {
                $created[] = 'clicks_'.$month->format('Y_m');
            }
        }

        return $created;
    }

    private function firstMonth(\DateTimeImmutable $now, int $months): \DateTimeImmutable
    {
        $fromWindow = self::startOfMonth($now->modify(\sprintf('-%d months', $months)));
        $oldestRecord = $this->connection->fetchOne("SELECT min(occurred_at) AT TIME ZONE 'UTC' FROM clicks");
        if (!\is_string($oldestRecord)) {
            return $fromWindow;
        }
        $fromData = self::startOfMonth(new \DateTimeImmutable($oldestRecord.' UTC'));

        return $fromData < $fromWindow ? $fromData : $fromWindow;
    }

    private function lastMonth(\DateTimeImmutable $now, int $horizon): \DateTimeImmutable
    {
        $toHorizon = self::startOfMonth($now->modify(\sprintf('+%d months', $horizon)));
        $newestRecord = $this->connection->fetchOne("SELECT max(occurred_at) AT TIME ZONE 'UTC' FROM clicks");
        if (!\is_string($newestRecord)) {
            return $toHorizon;
        }
        $fromData = self::startOfMonth(new \DateTimeImmutable($newestRecord.' UTC'));

        return $fromData > $toHorizon ? $fromData : $toHorizon;
    }

    /**
     * Drops every partition whose whole range is before the cutoff, and records
     * how far data has been removed — in one transaction, so no committed state
     * has the rows gone without the boundary moved, or the boundary moved
     * without the rows gone.
     *
     * A partition that straddles the cutoff is kept: its newer rows are inside
     * the window.
     *
     * What is recorded is the **end of the last month actually removed**, not
     * the configured cutoff. A two-month run on 16 September drops June and
     * keeps July, so it removed data through 1 July, not through 16 July —
     * recording the cutoff would have made a later first delivery from 10 July
     * permanently undeliverable although its month was never removed (Gate 2
     * round 1, finding 1).
     *
     * The exclusive advisory lock is the other half of that promise: a handler
     * holds the shared one across its own decision and insert, so a drop cannot
     * land between a handler deciding a click is still recordable and its
     * insert (finding 2).
     *
     * @return list<string>
     */
    private function dropExpired(\DateTimeImmutable $cutoff): array
    {
        /** @var list<string> $dropped */
        $dropped = $this->connection->transactional(function (Connection $connection) use ($cutoff): array {
            $connection->executeStatement('SELECT pg_advisory_xact_lock(?)', [ClickRetention::LOCK_KEY]);

            $dropped = [];
            $removedThrough = null;
            foreach ($this->partitions() as $partition => $upperBound) {
                if ($upperBound > $cutoff) {
                    continue;
                }
                $connection->executeStatement(\sprintf('DROP TABLE %s', $connection->quoteSingleIdentifier($partition)));
                $dropped[] = $partition;
                if (null === $removedThrough || $upperBound > $removedThrough) {
                    $removedThrough = $upperBound;
                }
            }
            if (null !== $removedThrough) {
                $this->retention->recordDroppedThrough($removedThrough);
            }

            return $dropped;
        });

        return $dropped;
    }

    /**
     * Every partition of `clicks` with the first instant it does NOT hold —
     * read from the catalogue rather than from the name, so a partition someone
     * created by hand is judged by its bounds like any other.
     *
     * @return array<string, \DateTimeImmutable>
     */
    private function partitions(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT child.relname AS partition,
                   (regexp_match(pg_get_expr(child.relpartbound, child.oid), 'TO \(''([^'']+)''\)'))[1] AS upper_bound
            FROM pg_class parent
            JOIN pg_inherits i ON i.inhparent = parent.oid
            JOIN pg_class child ON child.oid = i.inhrelid
            JOIN pg_namespace ns ON ns.oid = parent.relnamespace
            WHERE parent.relname = 'clicks' AND ns.nspname = current_schema()
            ORDER BY child.relname
            SQL);

        $partitions = [];
        foreach ($rows as $row) {
            $bound = $row['upper_bound'];
            if (!\is_string($row['partition']) || !\is_string($bound)) {
                continue;
            }
            $partitions[$row['partition']] = new \DateTimeImmutable($bound);
        }

        return $partitions;
    }

    private static function startOfMonth(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setDate((int) $moment->format('Y'), (int) $moment->format('n'), 1)->setTime(0, 0);
    }
}
