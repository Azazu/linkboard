<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Auth\Entity\User;
use App\Shared\Db\Row;
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
        self::assertSame(2, Row::toInt($c->fetchOne('SELECT count(*) FROM users')));
        self::assertSame(1, Row::toInt($c->fetchOne('SELECT count(*) FROM users WHERE roles::jsonb @> :roles::jsonb AND email = :email', ['roles' => json_encode([User::ROLE_ADMIN], \JSON_THROW_ON_ERROR), 'email' => DemoDataset::ADMIN_EMAIL])));
        $links = $c->fetchAllAssociative('SELECT l.id, l.slug, l.rules, l.click_count, u.email FROM links l JOIN users u ON u.id = l.owner_id ORDER BY l.slug');
        self::assertCount(10, $links);
        foreach ($links as $link) {
            $slug = Row::string($link, 'slug');
            self::assertSame(DemoDataset::USER_EMAIL, $link['email']);
            self::assertNotNull($link['rules'], $slug.' carries a routing document');
            self::assertSame(Row::int($link, 'click_count'), Row::toInt($c->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$link['id']])), $slug.' click_count equals its rows');
        }
        self::assertSame(500, Row::toInt($c->fetchOne('SELECT count(*) FROM clicks')));
        self::assertSame(0, Row::toInt($c->fetchOne("SELECT count(*) FROM clicks WHERE occurred_at < now() - interval '5 days' OR occurred_at > now()")));
        self::assertGreaterThan(0, Row::toInt($c->fetchOne('SELECT count(*) FROM clicks WHERE is_bot')));
        self::assertGreaterThan(0, Row::toInt($c->fetchOne('SELECT count(*) FROM clicks WHERE country IS NULL')));
        self::assertGreaterThan(0, Row::toInt($c->fetchOne("SELECT count(*) FROM clicks WHERE variant IS NOT NULL AND resolved_by = 'variant'")));
        self::assertSame(0, Row::toInt($c->fetchOne("SELECT count(*) FROM clicks WHERE (variant IS NULL) <> (resolved_by <> 'variant')")), 'variant and resolved_by agree');
        self::assertGreaterThan(0, Row::toInt($c->fetchOne('SELECT count(*) FROM clicks WHERE referer_host IS NULL')));
        self::assertGreaterThan(0, Row::toInt($c->fetchOne("SELECT count(*) FROM clicks WHERE resolved_by IN ('device', 'country', 'language')")));
        self::assertLessThan(500, Row::toInt($c->fetchOne('SELECT count(DISTINCT visitor_hash) FROM clicks')));
        self::assertSame(500, Row::toInt($c->fetchOne("SELECT count(*) FROM clicks WHERE visitor_hash ~ '^[0-9a-f]{64}$'")));
        self::assertSame(0, Row::toInt($c->fetchOne("SELECT count(*) FROM clicks WHERE coalesce(referer_host, '') ~ '^[0-9]+\\.[0-9]+\\.[0-9]+\\.[0-9]+$' OR coalesce(browser, '') ~ 'Mozilla'")), 'no raw IP or user agent');

        $password = self::printedPassword($display);
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => DemoDataset::USER_EMAIL, 'password' => $password]);
        self::assertResponseStatusCodeSame(200, 'the printed password logs the demo user in');

        // second run without --reset: refused, nothing changes
        $again = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        self::assertSame(1, $again->execute(['--clicks' => '5', '--days' => '1']));
        self::assertStringContainsString('--reset', $again->getDisplay());
        self::assertSame(500, Row::toInt($c->fetchOne('SELECT count(*) FROM clicks')));
        self::assertSame(2, Row::toInt($c->fetchOne('SELECT count(*) FROM users')));
        $oldIds = array_map(static fn (array $link): string => Row::string($link, 'id'), $links);

        // --reset: everything of the first run is gone, a new dataset exists
        $reset = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        self::assertSame(0, $reset->execute(['--reset' => true, '--clicks' => '300', '--days' => '3']), $reset->getDisplay());
        self::assertSame(2, Row::toInt($c->fetchOne('SELECT count(*) FROM users')));
        self::assertSame(10, Row::toInt($c->fetchOne('SELECT count(*) FROM links')));
        self::assertSame(300, Row::toInt($c->fetchOne('SELECT count(*) FROM clicks')));
        self::assertSame(0, Row::toInt($c->fetchOne("SELECT count(*) FROM clicks WHERE occurred_at < now() - interval '3 days'")));
        self::assertSame([], array_intersect($oldIds, Row::toStrings($c->fetchFirstColumn('SELECT id FROM links'), 'id')), 'the links of the first run are gone');
        self::assertNotSame($password, self::printedPassword($reset->getDisplay()), 'a new password');
    }

    /**
     * The printed password whatever its shape: the generated one is 24 hex
     * characters, an instance-supplied one is whatever the host set.
     */
    private static function printedPasswordOfAnyShape(string $display): string
    {
        if (1 !== preg_match('/demo@example\.com\s+password: (\S+)/', $display, $m)) {
            self::fail('no printed password for the demo user in: '.$display);
        }

        return $m[1];
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

    public function testAConfiguredPasswordSurvivesAReset(): void
    {
        // Gate 1 round 1, finding 1: with a password generated per run, the
        // first scheduled reload orphans whatever credential a visitor was
        // given, and the console output nobody reads is the only place the
        // replacement appears. This is the case that fails without the
        // instance's own password.
        //
        // The two seeds run back to back with no HTTP between them: a request
        // through the same kernel leaves the EntityManager holding the former
        // dataset, and the reset then dies on a cascade rather than on
        // anything this test is about.
        $configured = 'demo-password-for-this-test-only';
        // saved, not unset afterwards: `.env` declares DEMO_PASSWORD, and a
        // later test in this process would fail to build the container at all
        // if the variable disappeared
        $saved = \is_string($_SERVER['DEMO_PASSWORD'] ?? null) ? $_SERVER['DEMO_PASSWORD'] : '';
        $_SERVER['DEMO_PASSWORD'] = $configured;
        $_ENV['DEMO_PASSWORD'] = $configured;

        try {
            $client = self::createClient();
            $client->disableReboot();
            $kernel = self::$kernel;
            self::assertInstanceOf(KernelInterface::class, $kernel);

            $first = new CommandTester(new Application($kernel)->find('app:demo:seed'));
            self::assertSame(0, $first->execute(['--clicks' => '10', '--days' => '2']), $first->getDisplay());
            self::assertSame($configured, self::printedPasswordOfAnyShape($first->getDisplay()), 'the instance supplied it');

            // A fresh kernel for the reload. Two seeds through one
            // EntityManager die on a cascade — the first run's accounts are
            // still managed when the second removes and recreates them — and
            // that is an artefact of running both in one process, not
            // something a scheduled instance ever does.
            self::ensureKernelShutdown();
            $client = self::createClient();
            $client->disableReboot();
            $kernel = self::$kernel;
            self::assertInstanceOf(KernelInterface::class, $kernel);

            $reset = new CommandTester(new Application($kernel)->find('app:demo:seed'));
            self::assertSame(0, $reset->execute(['--reset' => true, '--clicks' => '10', '--days' => '2']), $reset->getDisplay());
            self::assertSame($configured, self::printedPasswordOfAnyShape($reset->getDisplay()), 'and the reload did not change it');

            // and it is not only printed: it signs in against the new dataset
            $client->request('POST', '/api/v1/auth/token', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], content: json_encode(['email' => DemoDataset::USER_EMAIL, 'password' => $configured], \JSON_THROW_ON_ERROR));
            self::assertSame(200, $client->getResponse()->getStatusCode());
        } finally {
            $_SERVER['DEMO_PASSWORD'] = $saved;
            $_ENV['DEMO_PASSWORD'] = $saved;
        }
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
        self::assertSame([0, 0, 0], [Row::toInt($c->fetchOne('SELECT count(*) FROM users')), Row::toInt($c->fetchOne('SELECT count(*) FROM links')), Row::toInt($c->fetchOne('SELECT count(*) FROM clicks'))]);

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
        self::assertSame(100, Row::toInt($c->fetchOne('SELECT count(*) FROM clicks')));
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
