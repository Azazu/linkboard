<?php

declare(strict_types=1);

namespace App\Analytics\Query;

use App\Analytics\Dto\TimeBucket;
use App\Analytics\Report\ReportRequest;
use Doctrine\DBAL\Connection;

/**
 * Spec analytics "Timeseries report": `generate_series` of UTC buckets left
 * joined to the `date_trunc` groups, zeros where nothing happened, running
 * total by a window (design decision 2). Without a link the same statement
 * serves the global timeseries.
 */
final readonly class TimeseriesQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<TimeBucket>
     */
    public function buckets(ReportRequest $request): array
    {
        $sql = <<<SQL
            WITH buckets AS (
                SELECT generate_series(
                    date_trunc(:unit, (:from)::timestamptz AT TIME ZONE 'UTC'),
                    date_trunc(:unit, ((:to)::timestamptz AT TIME ZONE 'UTC') - interval '1 microsecond'),
                    (:step)::interval
                ) AS bucket
            ), hits AS (
                SELECT date_trunc(:unit, occurred_at AT TIME ZONE 'UTC') AS bucket,
                       count(*) AS clicks,
                       count(DISTINCT visitor_hash) AS uniques
                FROM clicks
                WHERE occurred_at >= :from AND occurred_at < :to{$this->link($request)}{$this->bots($request)}
                GROUP BY 1
            )
            SELECT b.bucket,
                   coalesce(h.clicks, 0) AS clicks,
                   coalesce(h.uniques, 0) AS uniques,
                   sum(coalesce(h.clicks, 0)) OVER (ORDER BY b.bucket) AS cumulative
            FROM buckets b
            LEFT JOIN hits h USING (bucket)
            ORDER BY b.bucket
            SQL;
        $rows = $this->connection->fetchAllAssociative($sql, Sql::params($request) + [
            'unit' => $request->granularity->unit(),
            'step' => $request->granularity->step(),
        ], Sql::types());

        return array_map(static fn (array $row): TimeBucket => new TimeBucket(
            Sql::utc((string) $row['bucket']),
            (int) $row['clicks'],
            (int) $row['uniques'],
            (int) $row['cumulative'],
        ), $rows);
    }

    private function link(ReportRequest $request): string
    {
        return Sql::link($request);
    }

    private function bots(ReportRequest $request): string
    {
        return Sql::bots($request);
    }
}
