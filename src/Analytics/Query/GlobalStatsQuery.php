<?php

declare(strict_types=1);

namespace App\Analytics\Query;

use App\Analytics\Dto\GlobalTotals;
use App\Analytics\Report\ReportRequest;
use App\Shared\Db\Row;
use Doctrine\DBAL\Connection;

/** Spec analytics "Global statistics for administrators": the totals of the summary. */
final readonly class GlobalStatsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function totals(ReportRequest $request, \DateTimeImmutable $startOfToday): GlobalTotals
    {
        $sql = <<<SQL
            SELECT (SELECT count(*) FROM users) AS users,
                   (SELECT count(*) FROM links) AS links,
                   (SELECT count(*) FROM links WHERE is_active) AS active,
                   count(*) AS clicks,
                   count(*) FILTER (WHERE occurred_at >= :today) AS today
            FROM clicks
            WHERE true{$this->bots($request)}
            SQL;
        $row = $this->connection->fetchAssociative($sql, ['today' => $startOfToday], Sql::types());
        if (false === $row) {
            throw new \RuntimeException('The totals statement returned no row.');
        }

        return new GlobalTotals(Row::int($row, 'users'), Row::int($row, 'links'), Row::int($row, 'active'), Row::int($row, 'clicks'), Row::int($row, 'today'));
    }

    private function bots(ReportRequest $request): string
    {
        return Sql::bots($request);
    }
}
