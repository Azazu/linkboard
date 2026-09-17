<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Shared\Db\Row;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The test database accepts the dates this suite writes.
 *
 * The suite's click fixtures use fixed timestamps — the oldest is 2026-03-28 —
 * while the schema provisions months relative to today, so a retention window
 * measured from `now` would eventually leave them behind and the suite would
 * start failing on a calendar date rather than on a change (change
 * stretch-partition-clicks, Gate 1 confirmation 1, finding 1). `make test-db`
 * provisions a declared range through the same partition function; this is
 * what notices if that stops happening.
 */
#[CoversNothing]
final class FixtureMonthsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /** The oldest click any fixture in this suite writes. */
    private const string OLDEST_FIXTURE = '2026-03-28T12:00:00+00:00';

    /** CLICK_FIXTURE_FROM in the Makefile, which `make test-db` provisions from. */
    private const string DECLARED_RANGE_FROM = '2026-01-01';

    public function testTheOldestFixtureDateIsStorable(): void
    {
        $link = LinkFactory::createOne(['owner' => UserFactory::createOne()]);

        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO clicks (id, occurred_at, is_bot, visitor_hash, resolved_by, link_id)
                VALUES (gen_random_uuid(), :at, false, repeat('a', 64), 'default', :link)
                SQL,
            ['at' => self::OLDEST_FIXTURE, 'link' => $link->getId()->toRfc4122()],
        );

        self::assertSame(1, Row::toInt($this->connection()->fetchOne(
            'SELECT count(*) FROM clicks WHERE link_id = ? AND occurred_at = ?',
            [$link->getId()->toRfc4122(), self::OLDEST_FIXTURE],
        )));
    }

    public function testNoClickFixtureInTheSuiteIsOlderThanTheDeclaredRange(): void
    {
        // a click fixture dated before the provisioned range fails with "no
        // partition of relation"; this says so at the source instead. What it
        // does not see: a date built from a variable, or a call spread over
        // several lines — it reads the literal dates on the lines that write a
        // click.
        $dates = [];
        $scanned = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2))) as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            ++$scanned;
            foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $line) {
                if (!str_contains($line, 'click(') && !str_contains($line, 'ClickRows::')) {
                    continue;
                }
                preg_match_all("/'(20\d\d-[01]\d-[0-3]\d)T/", $line, $matches);
                foreach ($matches[1] as $date) {
                    $dates[] = $date;
                }
            }
        }

        self::assertGreaterThan(100, $scanned, 'the scan read no files, so it proves nothing');
        self::assertNotSame([], $dates, 'the scan found no dated click fixture, so it proves nothing');
        sort($dates);
        self::assertGreaterThanOrEqual(
            self::DECLARED_RANGE_FROM,
            $dates[0],
            \sprintf('make test-db provisions from %s; a click fixture older than that cannot be stored', self::DECLARED_RANGE_FROM),
        );
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
