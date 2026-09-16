<?php

declare(strict_types=1);

namespace App\Tests\Integration\Db;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `scripts/schema-fingerprint.sql` sees what it promises to see.
 *
 * The stub suite of `scripts/migrations_roundtrip_test.sh` proves the script
 * compares two listings; it cannot prove the query would have noticed a
 * leftover index, because a fake console can return whatever it likes. And
 * this repository's own migrations round-trip correctly, so the CI job would
 * stay green even if the query selected nothing but column names (change
 * harden-gate-floor, Gate 1 round 1, finding 3).
 *
 * So: a throwaway schema, `search_path` pointed at it — which is why the query
 * is written against `current_schema()` — and one case per category the
 * listing claims to cover. Remove an extraction from the query and one of
 * these turns red.
 *
 * DDL is transactional in PostgreSQL and `dama/doctrine-test-bundle` wraps
 * each test in a transaction, so the throwaway schema disappears with the
 * rollback.
 */
#[CoversNothing]
final class SchemaFingerprintTest extends KernelTestCase
{
    private const string SCHEMA = 'fingerprint_probe';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $this->connection->executeStatement('CREATE SCHEMA '.self::SCHEMA);
        $this->connection->executeStatement('SET search_path TO '.self::SCHEMA);
        $this->connection->executeStatement('CREATE TABLE widgets (id uuid NOT NULL, label varchar(32) NOT NULL, weight int DEFAULT 1, PRIMARY KEY (id))');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('SET search_path TO public');
        parent::tearDown();
    }

    public function testAnIndexAddedOrDroppedChangesTheFingerprintAndIsNamed(): void
    {
        $before = $this->fingerprint();
        $this->connection->executeStatement('CREATE INDEX idx_widgets_label ON widgets (label)');
        $after = $this->fingerprint();

        self::assertNotSame($before, $after, 'an index the listing did not see would round-trip silently');
        self::assertStringContainsString('idx_widgets_label', implode("\n", array_diff($after, $before)));

        $this->connection->executeStatement('DROP INDEX idx_widgets_label');
        self::assertSame($before, $this->fingerprint(), 'and dropping it puts the listing back');
    }

    public function testAColumnsNullabilityDefaultAndTypeEachChangeTheFingerprint(): void
    {
        foreach ([
            'nullability' => ['ALTER TABLE widgets ALTER COLUMN label DROP NOT NULL', 'label'],
            'default' => ['ALTER TABLE widgets ALTER COLUMN weight SET DEFAULT 7', 'weight'],
            'type' => ['ALTER TABLE widgets ALTER COLUMN label TYPE text', 'label'],
        ] as $case => [$statement, $column]) {
            $before = $this->fingerprint();
            $this->connection->executeStatement($statement);
            $after = $this->fingerprint();

            self::assertNotSame($before, $after, "a column's $case is part of the schema");
            $differs = implode("\n", array_diff($after, $before));
            self::assertStringContainsString("column widgets.$column", $differs, "the $case difference names the column");
        }
    }

    public function testAConstraintAddedOrDroppedChangesTheFingerprintAndIsNamed(): void
    {
        $before = $this->fingerprint();
        $this->connection->executeStatement('ALTER TABLE widgets ADD CONSTRAINT widgets_weight_positive CHECK (weight > 0)');
        $after = $this->fingerprint();

        self::assertNotSame($before, $after, 'a constraint the listing did not see would round-trip silently');
        self::assertStringContainsString('widgets_weight_positive', implode("\n", array_diff($after, $before)));

        $this->connection->executeStatement('ALTER TABLE widgets DROP CONSTRAINT widgets_weight_positive');
        self::assertSame($before, $this->fingerprint(), 'and dropping it puts the listing back');
    }

    public function testTheListingCoversTheThreeCategoriesItNames(): void
    {
        // a listing that lost a category would still pass every "differs" case
        // above if that case's object happened to change another category too
        $kinds = array_map(static fn (string $line): string => strtok($line, ' ') ?: '', $this->fingerprint());

        self::assertContains('column', $kinds);
        self::assertContains('index', $kinds, 'the primary key is an index');
        self::assertContains('constraint', $kinds, 'the primary key is a constraint too');
    }

    /**
     * @return list<string>
     */
    private function fingerprint(): array
    {
        $sql = file_get_contents(\dirname(__DIR__, 3).'/scripts/schema-fingerprint.sql');
        self::assertIsString($sql, 'the published query is the one under test');

        return array_map(
            static fn (array $row): string => \is_string($row['line']) ? $row['line'] : '',
            $this->connection->fetchAllAssociative($sql),
        );
    }
}
