<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * clicks becomes a range-partitioned table, one partition per calendar month on
 * `occurred_at` in UTC (brief §3.4 and §9, change stretch-partition-clicks).
 *
 * Hand-written, not diffed: `doctrine:migrations:diff` models neither a
 * partitioned table nor the function below.
 *
 * The conversion is rename → create → copy → drop rather than an ATTACH,
 * because the existing table's primary key is `id` alone and the partitioned
 * one needs `(id, occurred_at)` — PostgreSQL requires the partition key in
 * every unique constraint — so the rows are rewritten either way (design
 * decision 3). The indexes and the foreign key are created after the legacy
 * table is dropped, which is what frees their names.
 *
 * `clicks_ensure_partition()` is the single implementation of how a partition
 * is named and bounded: this migration calls it, and so does
 * `app:clicks:partitions`. Two implementations of one rule have drifted twice
 * in this repository's history; there is one here.
 */
final class Version20260916120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'clicks: monthly range partitioning on occurred_at, the partition function, and the retention boundary table (stretch-partition-clicks)';
    }

    public function up(Schema $schema): void
    {
        // One place that knows a partition's name and its bounds. Bounds are
        // written as explicit UTC instants so the session's TimeZone cannot
        // move a month boundary.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION clicks_ensure_partition(month date) RETURNS boolean AS $$
            DECLARE
                first_day date := date_trunc('month', month)::date;
                next_month date := (date_trunc('month', month) + interval '1 month')::date;
                part text := 'clicks_' || to_char(first_day, 'YYYY_MM');
            BEGIN
                IF to_regclass(part) IS NOT NULL THEN
                    RETURN false;
                END IF;
                EXECUTE format(
                    'CREATE TABLE %I PARTITION OF clicks FOR VALUES FROM (%L) TO (%L)',
                    part,
                    to_char(first_day, 'YYYY-MM-DD') || ' 00:00:00+00',
                    to_char(next_month, 'YYYY-MM-DD') || ' 00:00:00+00'
                );
                RETURN true;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        // How far click data has actually been removed. One row at most, written
        // by app:clicks:partitions inside the same transaction as the drops, and
        // never moved backwards: lengthening the retention window afterwards must
        // not make an already removed click recordable again (design decision 5b).
        $this->addSql(<<<'SQL'
            CREATE TABLE clicks_retention (
                singleton boolean NOT NULL DEFAULT true,
                dropped_through TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (singleton),
                CONSTRAINT clicks_retention_one_row CHECK (singleton)
            )
            SQL);

        $this->addSql('ALTER TABLE clicks RENAME TO clicks_legacy');
        $this->addSql(<<<'SQL'
            CREATE TABLE clicks (
                id UUID NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                country CHAR(2) DEFAULT NULL,
                device_type VARCHAR(16) DEFAULT NULL,
                os VARCHAR(16) DEFAULT NULL,
                browser VARCHAR(32) DEFAULT NULL,
                is_bot BOOLEAN DEFAULT false NOT NULL,
                referer_host VARCHAR(255) DEFAULT NULL,
                visitor_hash CHAR(64) NOT NULL,
                variant VARCHAR(16) DEFAULT NULL,
                resolved_by VARCHAR(8) NOT NULL,
                link_id UUID NOT NULL,
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
            SQL);

        // Every month a click may legitimately fall in: the retention window
        // back, the horizon forward, and any month the existing rows occupy, so
        // that a fresh database accepts the demo seed and the test fixtures
        // without further preparation.
        //
        // The two numbers are the DEFAULTS of CLICK_RETENTION_MONTHS and
        // CLICK_PARTITION_HORIZON_MONTHS. A migration cannot read the
        // environment, so `app:clicks:partitions` — which can — is the
        // authority afterwards, and a test asserts these literals still equal
        // the command's defaults, because two places holding one number is how
        // they drift.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                months integer := 13;
                horizon integer := 3;
                first_month date := least(
                    (date_trunc('month', now()) - make_interval(months => months))::date,
                    coalesce((SELECT date_trunc('month', min(occurred_at) AT TIME ZONE 'UTC')::date FROM clicks_legacy), CURRENT_DATE)
                );
                last_month date := greatest(
                    (date_trunc('month', now()) + make_interval(months => horizon))::date,
                    coalesce((SELECT date_trunc('month', max(occurred_at) AT TIME ZONE 'UTC')::date FROM clicks_legacy), CURRENT_DATE)
                );
                cursor_month date := first_month;
            BEGIN
                WHILE cursor_month <= last_month LOOP
                    PERFORM clicks_ensure_partition(cursor_month);
                    cursor_month := (cursor_month + interval '1 month')::date;
                END LOOP;
            END $$
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO clicks (id, occurred_at, country, device_type, os, browser, is_bot, referer_host, visitor_hash, variant, resolved_by, link_id)
            SELECT id, occurred_at, country, device_type, os, browser, is_bot, referer_host, visitor_hash, variant, resolved_by, link_id
            FROM clicks_legacy
            SQL);
        $this->addSql('DROP TABLE clicks_legacy');

        $this->addSql('CREATE INDEX idx_clicks_link_occurred ON clicks (link_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_clicks_link_occurred_human ON clicks (link_id, occurred_at) WHERE NOT is_bot');
        $this->addSql('CREATE INDEX IDX_20DA1901ADA40271 ON clicks (link_id)');
        $this->addSql('ALTER TABLE clicks ADD CONSTRAINT FK_20DA1901ADA40271 FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // The inverse, rows included: an ordinary table keyed by id alone, every
        // surviving row copied back, then the partitioned table and everything
        // this migration created.
        $this->addSql(<<<'SQL'
            CREATE TABLE clicks_plain (
                id UUID NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                country CHAR(2) DEFAULT NULL,
                device_type VARCHAR(16) DEFAULT NULL,
                os VARCHAR(16) DEFAULT NULL,
                browser VARCHAR(32) DEFAULT NULL,
                is_bot BOOLEAN DEFAULT false NOT NULL,
                referer_host VARCHAR(255) DEFAULT NULL,
                visitor_hash CHAR(64) NOT NULL,
                variant VARCHAR(16) DEFAULT NULL,
                resolved_by VARCHAR(8) NOT NULL,
                link_id UUID NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO clicks_plain (id, occurred_at, country, device_type, os, browser, is_bot, referer_host, visitor_hash, variant, resolved_by, link_id)
            SELECT id, occurred_at, country, device_type, os, browser, is_bot, referer_host, visitor_hash, variant, resolved_by, link_id
            FROM clicks
            SQL);
        $this->addSql('DROP TABLE clicks');
        $this->addSql('ALTER TABLE clicks_plain RENAME TO clicks');
        $this->addSql('CREATE INDEX idx_clicks_link_occurred ON clicks (link_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_clicks_link_occurred_human ON clicks (link_id, occurred_at) WHERE NOT is_bot');
        $this->addSql('CREATE INDEX IDX_20DA1901ADA40271 ON clicks (link_id)');
        $this->addSql('ALTER TABLE clicks ADD CONSTRAINT FK_20DA1901ADA40271 FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('DROP TABLE clicks_retention');
        $this->addSql('DROP FUNCTION clicks_ensure_partition(date)');
    }
}
