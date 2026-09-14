<?php

declare(strict_types=1);

namespace App\Analytics\Query;

use App\Analytics\Dto\ClickBucket;
use App\Analytics\Dto\OwnerTotals;
use App\Analytics\Report\Period;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * The dashboard's figures for one owner (design decision 4 of add-web-ui).
 * Every other report in this capability is either about one link or about the
 * whole instance; a person's own numbers are neither, so they get a query
 * whose scope is `links.owner_id` and which never sees a link id from the
 * caller.
 *
 * Deliberately not behind ReportCache: that cache is invalidated by link tag,
 * so a dashboard keyed by owner would stay stale for the cache's lifetime
 * after a link is created or deleted through the API — an invalidation hole
 * across two layers, to save two index-backed statements on a page one person
 * opens. If the figures ever grow expensive, the fix is an owner tag on the
 * cache and invalidation inside the link use cases.
 *
 * Bots are excluded like everywhere else in the capability (spec analytics).
 */
final readonly class OwnerDashboardQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function totals(Uuid $ownerId, \DateTimeImmutable $startOfToday, bool $includeBots = false): OwnerTotals
    {
        $bots = $includeBots ? '' : ' AND NOT c.is_bot';
        $sql = <<<SQL
            SELECT
                (SELECT count(*) FROM links WHERE owner_id = :owner) AS links,
                (SELECT count(*) FROM links WHERE owner_id = :owner AND is_active) AS active_links,
                count(c.id) AS clicks,
                count(DISTINCT c.visitor_hash) AS uniques,
                count(*) FILTER (WHERE c.occurred_at >= :today) AS clicks_today
            FROM clicks c
            JOIN links l ON l.id = c.link_id
            WHERE l.owner_id = :owner{$bots}
            SQL;

        $row = $this->connection->fetchAssociative($sql, [
            'owner' => $ownerId->toRfc4122(),
            'today' => $startOfToday,
        ], ['today' => Types::DATETIMETZ_IMMUTABLE]);
        if (false === $row) {
            throw new \RuntimeException('The owner totals returned no row.');
        }

        return new OwnerTotals(
            (int) $row['links'],
            (int) $row['active_links'],
            (int) $row['clicks'],
            (int) $row['uniques'],
            (int) $row['clicks_today'],
        );
    }

    /**
     * Clicks per day over the owner's links, zero-filled across the period and
     * with a running total — the same shape the link timeseries has.
     *
     * @return list<ClickBucket>
     */
    public function daily(Uuid $ownerId, Period $period, bool $includeBots = false): array
    {
        $bots = $includeBots ? '' : ' AND NOT c.is_bot';
        $sql = <<<SQL
            WITH buckets AS (
                SELECT generate_series(
                    date_trunc('day', (:from)::timestamptz AT TIME ZONE 'UTC'),
                    date_trunc('day', ((:to)::timestamptz AT TIME ZONE 'UTC') - interval '1 microsecond'),
                    interval '1 day'
                ) AS bucket
            ), hits AS (
                SELECT date_trunc('day', c.occurred_at AT TIME ZONE 'UTC') AS bucket,
                       count(*) AS clicks
                FROM clicks c
                JOIN links l ON l.id = c.link_id
                WHERE l.owner_id = :owner AND c.occurred_at >= :from AND c.occurred_at < :to{$bots}
                GROUP BY 1
            )
            SELECT b.bucket,
                   coalesce(h.clicks, 0) AS clicks,
                   sum(coalesce(h.clicks, 0)) OVER (ORDER BY b.bucket) AS cumulative
            FROM buckets b
            LEFT JOIN hits h USING (bucket)
            ORDER BY b.bucket
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'owner' => $ownerId->toRfc4122(),
            'from' => $period->from,
            'to' => $period->to,
        ], ['from' => Types::DATETIMETZ_IMMUTABLE, 'to' => Types::DATETIMETZ_IMMUTABLE]);

        return array_map(static fn (array $row): ClickBucket => new ClickBucket(
            Sql::utc((string) $row['bucket']),
            (int) $row['clicks'],
            (int) $row['cumulative'],
        ), $rows);
    }
}
