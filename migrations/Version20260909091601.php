<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909091601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'links: the link aggregate — case-sensitive unique slug (collation C), owner FK with cascade, positive click limit, owner/created index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE links (id UUID NOT NULL, slug VARCHAR(32) NOT NULL COLLATE "C", target_url TEXT NOT NULL, rules JSONB DEFAULT NULL, utm JSONB DEFAULT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, max_clicks INT DEFAULT NULL, click_count INT DEFAULT 0 NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D182A118989D9B62 ON links (slug)');
        $this->addSql('CREATE INDEX idx_links_owner_created ON links (owner_id, created_at)');
        $this->addSql('CREATE INDEX IDX_D182A1187E3C61F9 ON links (owner_id)');
        $this->addSql('ALTER TABLE links ADD CONSTRAINT FK_D182A1187E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        // FR-LNK-7: a click limit is a positive integer or null (not expressible in the Doctrine mapping)
        $this->addSql('ALTER TABLE links ADD CONSTRAINT links_max_clicks_positive CHECK (max_clicks IS NULL OR max_clicks > 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE links DROP CONSTRAINT FK_D182A1187E3C61F9');
        $this->addSql('DROP TABLE links');
    }
}
