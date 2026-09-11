<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Auth\Entity\User;
use App\Shared\Demo\DemoDataset;
use App\Shared\Demo\DemoSeedCommand;
use App\Tests\Fixture\FailingStatement;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec demo-data "Demo dataset" (small seed) and "Guards and re-runs" against
 * PostgreSQL; the printed password logs the demo user in over HTTP.
 */
#[CoversClass(DemoSeedCommand::class)]
#[CoversClass(DemoDataset::class)]
final class DemoSeedCommandTest extends WebTestCase
{
    use ResetDatabase;

    public function testSmallSeedThenRefusalThenReset(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);
        $tester = new CommandTester(new Application($kernel)->find('app:demo:seed'));

        self::assertSame(0, $tester->execute(['--clicks' => '500', '--days' => '5']), $tester->getDisplay());
        $display = $tester->getDisplay();

        $c = self::connection();
        self::assertSame(2, (int) $c->fetchOne('SELECT count(*) FROM users'));
        self::assertSame(1, (int) $c->fetchOne('SELECT count(*) FROM users WHERE roles::jsonb @> :roles::jsonb AND email = :email', ['roles' => json_encode([User::ROLE_ADMIN], \JSON_THROW_ON_ERROR), 'email' => DemoDataset::ADMIN_EMAIL]));
        $links = $c->fetchAllAssociative('SELECT l.id, l.slug, l.rules, l.click_count, u.email FROM links l JOIN users u ON u.id = l.owner_id ORDER BY l.slug');
        self::assertCount(10, $links);
        foreach ($links as $link) {
            self::assertSame(DemoDataset::USER_EMAIL, $link['email']);
            self::assertNotNull($link['rules'], $link['slug'].' carries a routing document');
            self::assertSame((int) $link['click_count'], (int) $c->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$link['id']]), $link['slug'].' click_count equals its rows');
        }
        self::assertSame(500, (int) $c->fetchOne('SELECT count(*) FROM clicks'));
        self::assertSame(0, (int) $c->fetchOne("SELECT count(*) FROM clicks WHERE occurred_at < now() - interval '5 days' OR occurred_at > now()"));
        self::assertGreaterThan(0, (int) $c->fetchOne('SELECT count(*) FROM clicks WHERE is_bot'));
        self::assertGreaterThan(0, (int) $c->fetchOne('SELECT count(*) FROM clicks WHERE country IS NULL'));
        self::assertGreaterThan(0, (int) $c->fetchOne("SELECT count(*) FROM clicks WHERE variant IS NOT NULL AND resolved_by = 'variant'"));
        self::assertSame(0, (int) $c->fetchOne("SELECT count(*) FROM clicks WHERE (variant IS NULL) <> (resolved_by <> 'variant')"), 'variant and resolved_by agree');
        self::assertGreaterThan(0, (int) $c->fetchOne('SELECT count(*) FROM clicks WHERE referer_host IS NULL'));
        self::assertGreaterThan(0, (int) $c->fetchOne("SELECT count(*) FROM clicks WHERE resolved_by IN ('device', 'country', 'language')"));
        self::assertLessThan(500, (int) $c->fetchOne('SELECT count(DISTINCT visitor_hash) FROM clicks'));
        self::assertSame(500, (int) $c->fetchOne("SELECT count(*) FROM clicks WHERE visitor_hash ~ '^[0-9a-f]{64}$'"));
        self::assertSame(0, (int) $c->fetchOne("SELECT count(*) FROM clicks WHERE coalesce(referer_host, '') ~ '^[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$' OR coalesce(browser, '') ~ 'Mozilla'"), 'no raw IP or user agent');

        $password = self::printedPassword($display);
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => DemoDataset::USER_EMAIL, 'password' => $password]);
        self::assertResponseStatusCodeSame(200, 'the printed password logs the demo user in');

        // second run without --reset: refused, nothing changes
        $again = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        self::assertSame(1, $again->execute(['--clicks' => '5', '--days' => '1']));
        self::assertStringContainsString('--reset', $again->getDisplay());
        self::assertSame(500, (int) $c->fetchOne('SELECT count(*) FROM clicks'));
        self::assertSame(2, (int) $c->fetchOne('SELECT count(*) FROM users'));
        $oldIds = array_column($links, 'id');

        // --reset: everything of the first run is gone, a new dataset exists
        $reset = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        self::assertSame(0, $reset->execute(['--reset' => true, '--clicks' => '300', '--days' => '3']), $reset->getDisplay());
        self::assertSame(2, (int) $c->fetchOne('SELECT count(*) FROM users'));
        self::assertSame(10, (int) $c->fetchOne('SELECT count(*) FROM links'));
        self::assertSame(300, (int) $c->fetchOne('SELECT count(*) FROM clicks'));
        self::assertSame(0, (int) $c->fetchOne("SELECT count(*) FROM clicks WHERE occurred_at < now() - interval '3 days'"));
        self::assertSame([], array_intersect($oldIds, $c->fetchFirstColumn('SELECT id FROM links')), 'the links of the first run are gone');
        self::assertNotSame($password, self::printedPassword($reset->getDisplay()), 'a new password');
    }

    private static function printedPassword(string $display): string
    {
        if (1 !== preg_match('/demo@example\.com\s+password: ([0-9a-f]{24})/', $display, $m)) {
            self::fail('no printed password for the demo user in: '.$display);
        }

        return $m[1];
    }

    protected function tearDown(): void
    {
        FailingStatement::reset();
        parent::tearDown();
    }

    public function testAFailureRollsBackAFreshSeedAndAReset(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);
        $c = self::connection();

        // fresh seed failing while the click rows are written: nothing exists afterwards
        FailingStatement::failOn('UPDATE links SET click_count');
        $failed = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        self::assertSame(1, $failed->execute(['--clicks' => '50', '--days' => '2']));
        self::assertStringContainsString('Injected failure on: UPDATE links SET click_count', $failed->getDisplay(), 'the run died on the injected failure, not earlier');
        self::assertSame([0, 0, 0], [(int) $c->fetchOne('SELECT count(*) FROM users'), (int) $c->fetchOne('SELECT count(*) FROM links'), (int) $c->fetchOne('SELECT count(*) FROM clicks')]);

        // a good dataset
        $ok = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        self::assertSame(0, $ok->execute(['--clicks' => '100', '--days' => '3']), $ok->getDisplay());
        $password = self::printedPassword($ok->getDisplay());
        $userIds = $c->fetchFirstColumn('SELECT id FROM users ORDER BY email');
        $linkIds = $c->fetchFirstColumn('SELECT id FROM links ORDER BY slug');
        self::assertCount(10, $linkIds);
        self::getContainer()->get('doctrine')->getManager()->clear(); // every CLI run is a fresh process; the kernel is shared here

        // a reset failing after the former accounts were deleted and the replacement accounts, links and rows written
        FailingStatement::failOn('UPDATE links SET click_count');
        $reset = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        self::assertSame(1, $reset->execute(['--reset' => true, '--clicks' => '40', '--days' => '1']));
        self::assertStringContainsString('Injected failure on: UPDATE links SET click_count', $reset->getDisplay(), 'the run died on the injected failure, after the former accounts were deleted and the replacement rows written');
        self::assertSame($userIds, $c->fetchFirstColumn('SELECT id FROM users ORDER BY email'), 'the former accounts, same ids');
        self::assertSame($linkIds, $c->fetchFirstColumn('SELECT id FROM links ORDER BY slug'), 'the former links, no replacement');
        self::assertSame(100, (int) $c->fetchOne('SELECT count(*) FROM clicks'));
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => DemoDataset::USER_EMAIL, 'password' => $password]);
        self::assertResponseStatusCodeSame(200, 'the former password still logs in');
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
