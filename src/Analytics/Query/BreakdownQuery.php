<?php

declare(strict_types=1);

namespace App\Analytics\Query;

use App\Analytics\Dto\CountryRow;
use App\Analytics\Dto\Devices;
use App\Analytics\Dto\DeviceTypeRow;
use App\Analytics\Dto\Grouped;
use App\Analytics\Dto\OsRow;
use App\Analytics\Dto\RefererRow;
use App\Analytics\Dto\TopLinkRow;
use App\Analytics\Dto\VariantRow;
use App\Analytics\Report\ReportRequest;
use Doctrine\DBAL\Connection;

/**
 * Spec analytics "Countries", "Devices", "Referrers", "Variants" reports and
 * the admin "top links": one template — count per group, share by
 * `sum() OVER ()`, `rank() OVER (ORDER BY count DESC)` so ties share a rank,
 * the period's total carried on every row (design decision 2). Devices and
 * variants have bounded vocabularies and take no limit.
 */
final readonly class BreakdownQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return Grouped<CountryRow>
     */
    public function countries(ReportRequest $request): Grouped
    {
        [$total, $rows] = $this->grouped('country', $request, limit: true);

        return new Grouped($total, array_map(static fn (array $r): CountryRow => new CountryRow(
            null === $r['key'] ? null : (string) $r['key'], (int) $r['clicks'], (float) $r['share'], (int) $r['rank'],
        ), $rows));
    }

    /**
     * @return Grouped<RefererRow>
     */
    public function referrers(ReportRequest $request): Grouped
    {
        [$total, $rows] = $this->grouped("coalesce(referer_host, 'direct')", $request, limit: true);

        return new Grouped($total, array_map(static fn (array $r): RefererRow => new RefererRow(
            (string) $r['key'], (int) $r['clicks'], (float) $r['share'], (int) $r['rank'],
        ), $rows));
    }

    public function devices(ReportRequest $request): Devices
    {
        [$total, $byType] = $this->grouped('device_type', $request, limit: false);
        [, $byOs] = $this->grouped('os', $request, limit: false);

        return new Devices(
            $total,
            array_map(static fn (array $r): DeviceTypeRow => new DeviceTypeRow(null === $r['key'] ? null : (string) $r['key'], (int) $r['clicks'], (float) $r['share']), $byType),
            array_map(static fn (array $r): OsRow => new OsRow(null === $r['key'] ? null : (string) $r['key'], (int) $r['clicks'], (float) $r['share']), $byOs),
        );
    }

    /**
     * Only clicks resolved by a variant take part; their variant is never null.
     *
     * @return Grouped<VariantRow>
     */
    public function variants(ReportRequest $request): Grouped
    {
        [$total, $rows] = $this->grouped('variant', $request, limit: false, extraWhere: " AND resolved_by = 'variant'");

        return new Grouped($total, array_map(static fn (array $r): VariantRow => new VariantRow(
            (string) $r['key'], (int) $r['clicks'], (int) $r['uniques'], (float) $r['share'],
        ), $rows));
    }

    /**
     * Global: the links with the most clicks in the period, with their slug and owner.
     *
     * @return Grouped<TopLinkRow>
     */
    public function topLinks(ReportRequest $request): Grouped
    {
        if (null !== $request->linkId) {
            throw new \InvalidArgumentException('The top-links report is global.');
        }
        $sql = <<<SQL
            SELECT c.link_id AS key, l.slug, l.owner_id,
                   count(*) AS clicks,
                   count(DISTINCT c.visitor_hash) AS uniques,
                   round(100.0 * count(*) / sum(count(*)) OVER (), 1) AS share,
                   rank() OVER (ORDER BY count(*) DESC) AS rank,
                   sum(count(*)) OVER () AS total
            FROM clicks c
            JOIN links l ON l.id = c.link_id
            WHERE c.occurred_at >= :from AND c.occurred_at < :to{$this->bots($request, 'c.')}
            GROUP BY c.link_id, l.slug, l.owner_id
            ORDER BY clicks DESC, l.slug
            LIMIT :limit
            SQL;
        $rows = $this->connection->fetchAllAssociative($sql, Sql::params($request) + ['limit' => $request->limit], Sql::types());

        return new Grouped((int) ($rows[0]['total'] ?? 0), array_map(static fn (array $r): TopLinkRow => new TopLinkRow(
            (string) $r['key'], (string) $r['slug'], (string) $r['owner_id'], (int) $r['clicks'], (int) $r['uniques'], (int) $r['rank'],
        ), $rows));
    }

    /**
     * @param string $key a column name or a constant SQL expression over columns — never request data
     *
     * @return array{int, list<array<string, mixed>>}
     */
    private function grouped(string $key, ReportRequest $request, bool $limit, string $extraWhere = ''): array
    {
        $limitSql = $limit ? ' LIMIT :limit' : '';
        $sql = <<<SQL
            SELECT {$key} AS key,
                   count(*) AS clicks,
                   count(DISTINCT visitor_hash) AS uniques,
                   round(100.0 * count(*) / sum(count(*)) OVER (), 1) AS share,
                   rank() OVER (ORDER BY count(*) DESC) AS rank,
                   sum(count(*)) OVER () AS total
            FROM clicks
            WHERE occurred_at >= :from AND occurred_at < :to{$this->link($request)}{$this->bots($request)}{$extraWhere}
            GROUP BY 1
            ORDER BY clicks DESC, key NULLS LAST{$limitSql}
            SQL;
        $params = Sql::params($request);
        if ($limit) {
            $params['limit'] = $request->limit;
        }
        $rows = $this->connection->fetchAllAssociative($sql, $params, Sql::types());

        return [(int) ($rows[0]['total'] ?? 0), $rows];
    }

    private function link(ReportRequest $request): string
    {
        return Sql::link($request);
    }

    private function bots(ReportRequest $request, string $alias = ''): string
    {
        return $request->includeBots ? '' : " AND NOT {$alias}is_bot";
    }
}
