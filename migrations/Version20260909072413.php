<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260909072413 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users: accounts with roles, blocking flag and a case-insensitive unique email (functional index on lower(email))';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles JSONB NOT NULL, is_blocked BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        // Uniqueness is case-insensitive (specification §3.1); the repository queries LOWER(email) so this index serves lookups too.
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email_lower ON users (LOWER(email))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_users_email_lower');
        $this->addSql('DROP TABLE users');
    }
}
