<?php

declare(strict_types=1);

namespace App\Analytics\Query;

use App\Analytics\Dto\SummaryFigures;
use App\Analytics\Report\ReportRequest;
use Doctrine\DBAL\Connection;

/**
 * Spec analytics "Summary report": one statement over the link's rows with
 * FILTER clauses for the period, the previous period and today, plus the
 * all-time aggregates; the delta is a CASE in SQL (design decision 2).
 */
final readonly class LinkSummaryQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function figures(ReportRequest $request, \DateTimeImmutable $startOfToday): SummaryFigures
    {
        if (null === $request->linkId) {
            throw new \InvalidArgumentException('The summary report is per link.');
        }
        $sql = <<<SQL
            SELECT s.*, CASE WHEN s.in_previous > 0
                             THEN round(100.0 * (s.in_period - s.in_previous) / s.in_previous, 1)
                        END AS delta
            FROM (
                SELECT count(*) AS total,
                       count(DISTINCT visitor_hash) AS uniques,
                       min(occurred_at) AS first_at,
                       max(occurred_at) AS last_at,
                       count(*) FILTER (WHERE occurred_at >= :today) AS today,
                       count(*) FILTER (WHERE occurred_at >= :from AND occurred_at < :to) AS in_period,
                       count(*) FILTER (WHERE occurred_at >= :prev_from AND occurred_at < :from) AS in_previous
                FROM clicks
                WHERE link_id = :link_id{$this->bots($request)}
            ) s
            SQL;
        $row = $this->connection->fetchAssociative($sql, Sql::params($request) + [
            'prev_from' => $request->period->previous()->from,
            'today' => $startOfToday,
        ], Sql::types());
        if (false === $row) {
            throw new \RuntimeException('The summary statement returned no row.');
        }

        return new SummaryFigures(
            (int) $row['total'],
            (int) $row['uniques'],
            null === $row['first_at'] ? null : Sql::utc((string) $row['first_at']),
            null === $row['last_at'] ? null : Sql::utc((string) $row['last_at']),
            (int) $row['today'],
            (int) $row['in_period'],
            (int) $row['in_previous'],
            null === $row['delta'] ? null : (float) $row['delta'],
        );
    }

    private function bots(ReportRequest $request): string
    {
        return Sql::bots($request);
    }
}
