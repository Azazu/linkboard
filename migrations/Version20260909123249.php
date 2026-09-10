<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * clicks: the click write model (specification §3.4). One row per redirect;
 * the hot path inserts through the DBAL. Indexes: (link_id, occurred_at) for
 * per-link time ranges — ascending, a btree serves ORDER BY … DESC by backward
 * scan — plus the same columns filtered to human clicks (WHERE NOT is_bot)
 * for the default reports; the single-column link_id index is the one
 * Doctrine maintains for the foreign key. Rows follow their link (ON DELETE
 * CASCADE). No user_agent column by design (data minimisation).
 */
final class Version20260909123249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'clicks: click write model — one row per redirect, FK to links ON DELETE CASCADE, (link_id, occurred_at) index plus its NOT is_bot partial twin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE clicks (id UUID NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, country CHAR(2) DEFAULT NULL, device_type VARCHAR(16) DEFAULT NULL, os VARCHAR(16) DEFAULT NULL, browser VARCHAR(32) DEFAULT NULL, is_bot BOOLEAN DEFAULT false NOT NULL, referer_host VARCHAR(255) DEFAULT NULL, visitor_hash CHAR(64) NOT NULL, variant VARCHAR(16) DEFAULT NULL, resolved_by VARCHAR(8) NOT NULL, link_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_clicks_link_occurred ON clicks (link_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_clicks_link_occurred_human ON clicks (link_id, occurred_at) WHERE NOT is_bot');
        $this->addSql('CREATE INDEX IDX_20DA1901ADA40271 ON clicks (link_id)');
        $this->addSql('ALTER TABLE clicks ADD CONSTRAINT FK_20DA1901ADA40271 FOREIGN KEY (link_id) REFERENCES links (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clicks DROP CONSTRAINT FK_20DA1901ADA40271');
        $this->addSql('DROP TABLE clicks');
    }
}
