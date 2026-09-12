<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Health;

use App\Shared\Health\BoundedRedisConnection;
use App\Shared\Health\KeyLookupProcess;
use App\Shared\Health\ProbeAuthorizer;
use App\Shared\Health\RedisOperationFailed;
use App\Tests\Support\CommittedProbeKeys;
use App\Tests\Support\FixtureProcess;
use App\Tests\Support\ProbeTestEnvironment;
use App\Tests\Support\RunningProcesses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The database phase against real PostgreSQL and the failure fixtures (design
 * decision 9): every answer of the child, and the deadline the parent enforces
 * by killing it — the lost-response case no in-process client can pass.
 */
#[CoversClass(KeyLookupProcess::class)]
#[CoversClass(BoundedRedisConnection::class)]
final class KeyLookupProcessTest extends TestCase
{
    private const float ALLOWANCE = ProbeAuthorizer::DATABASE_ALLOWANCE;

    private CommittedProbeKeys $rows;
    private KeyLookupProcess $lookup;

    protected function setUp(): void
    {
        $this->rows = CommittedProbeKeys::connect(ProbeTestEnvironment::testDatabaseUrl());
        $this->lookup = new KeyLookupProcess(ProbeTestEnvironment::testDatabaseUrl(), \dirname(__DIR__, 4));
    }

    protected function tearDown(): void
    {
        $this->rows->cleanup();
    }

    public function testAnAdminKeyIsVerifiedWithItsExpiry(): void
    {
        $expiresAt = new \DateTimeImmutable('2031-06-01T12:00:00+00:00');
        $plaintext = $this->rows->key($this->rows->user(), $expiresAt);

        $outcome = $this->lookup->lookup(hash('sha256', $plaintext), self::ALLOWANCE);

        self::assertTrue($outcome->isVerified());
        self::assertSame($expiresAt->getTimestamp(), $outcome->expiresAt?->getTimestamp());
    }

    public function testAnAdminKeyWithoutExpiryIsVerifiedWithNull(): void
    {
        $plaintext = $this->rows->key($this->rows->user());

        $outcome = $this->lookup->lookup(hash('sha256', $plaintext), self::ALLOWANCE);

        self::assertTrue($outcome->isVerified());
        self::assertNull($outcome->expiresAt);
    }

    public function testEveryOtherKeyIsDenied(): void
    {
        $cases = [
            'non-admin' => $this->rows->key($this->rows->user(admin: false)),
            'blocked admin' => $this->rows->key($this->rows->user(blocked: true)),
            'revoked' => $this->rows->key($this->rows->user(), revoked: true),
            'expired' => $this->rows->key($this->rows->user(), new \DateTimeImmutable('-1 minute')),
            'unknown' => 'lb_'.str_repeat('x', 40),
        ];

        foreach ($cases as $case => $plaintext) {
            self::assertTrue($this->lookup->lookup(hash('sha256', $plaintext), self::ALLOWANCE)->isDenied(), $case);
        }
    }

    public function testAClosedPortIsUnavailableAtOnce(): void
    {
        $lookup = new KeyLookupProcess(ProbeTestEnvironment::closedDatabaseUrl(), \dirname(__DIR__, 4));

        $started = microtime(true);
        $outcome = $lookup->lookup(hash('sha256', 'lb_whatever'), self::ALLOWANCE);

        self::assertTrue($outcome->isUnavailable());
        self::assertSame('PDOException', $outcome->reason);
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testAConnectionThatNeverCompletesIsUnavailableWithinTheAllowance(): void
    {
        $blackHole = FixtureProcess::start('black-hole-socket.php');
        try {
            $lookup = new KeyLookupProcess(ProbeTestEnvironment::withEndpoint(ProbeTestEnvironment::testDatabaseUrl(), '127.0.0.1', $blackHole->port), \dirname(__DIR__, 4));

            $started = microtime(true);
            $outcome = $lookup->lookup(hash('sha256', 'lb_whatever'), self::ALLOWANCE);
            $elapsed = microtime(true) - $started;
        } finally {
            $blackHole->stop();
        }

        self::assertTrue($outcome->isUnavailable());
        self::assertLessThan(3.0, $elapsed, 'libpq connect_timeout (2 s) or the kill deadline (2.5 s) ends it');
        self::assertSame([], RunningProcesses::lookupChildren());
    }

    public function testAStalledStatementIsKilledAtTheAllowanceAndLeavesNoChild(): void
    {
        $plaintext = $this->rows->key($this->rows->user());
        $hash = hash('sha256', $plaintext);
        $this->rows->lockApiKeys();
        try {
            $started = microtime(true);
            $outcome = $this->lookup->lookup($hash, self::ALLOWANCE);
            $elapsed = microtime(true) - $started;
        } finally {
            $this->rows->unlock();
        }

        self::assertTrue($outcome->isUnavailable());
        self::assertSame('ProcessTimedOutException', $outcome->reason);
        self::assertEqualsWithDelta(self::ALLOWANCE, $elapsed, 0.3);
        self::assertSame([], RunningProcesses::lookupChildren(), 'the child is killed, not left waiting on the lock');
    }

    public function testTheHashIsNotOnTheChildCommandLine(): void
    {
        // The lookup is driven from a sibling process so that this test can look
        // at the running child (ps-equivalent: /proc/*/cmdline) while the table
        // lock holds it in its statement.
        $plaintext = $this->rows->key($this->rows->user());
        $hash = hash('sha256', $plaintext);
        $root = \dirname(__DIR__, 4);
        $driver = new Process(['php', '-r', <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $o = (new App\Shared\Health\KeyLookupProcess($argv[2], $argv[1]))->lookup(trim(stream_get_contents(STDIN)), (float) $argv[3]);
            echo $o->status->value;
            PHP, $root, ProbeTestEnvironment::testDatabaseUrl(), (string) self::ALLOWANCE], $root, null, $hash, 30);

        $this->rows->lockApiKeys();
        try {
            $driver->start();
            $seen = [];
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline && [] === $seen) {
                usleep(50_000);
                $seen = RunningProcesses::lookupChildren();
            }
            self::assertNotSame([], $seen, 'the lookup child runs while the table is locked');
            foreach (RunningProcesses::commandLines() as $line) {
                self::assertStringNotContainsString($hash, $line, 'the hash travels on stdin, never on a command line');
            }
            $driver->wait(); // the lock holds until the child is killed at its deadline
        } finally {
            $this->rows->unlock();
        }

        self::assertSame('U', $driver->getOutput(), $driver->getErrorOutput());
        self::assertSame([], RunningProcesses::lookupChildren());
    }

    public function testALostResponseAfterTheHandshakeIsKilledAtTheAllowance(): void
    {
        // The case no in-process PostgreSQL client passes: the handshake completes,
        // the query is sent, the answer never arrives — the proxy swallows it.
        $plaintext = $this->rows->key($this->rows->user());
        $upstream = parse_url(ProbeTestEnvironment::testDatabaseUrl());
        \assert(\is_array($upstream) && isset($upstream['host']));
        $proxy = FixtureProcess::start('tcp-proxy.php', [\sprintf('--upstream=%s:%d', $upstream['host'], $upstream['port'] ?? 5432), '--stall-after-responses=1']);
        try {
            $lookup = new KeyLookupProcess(ProbeTestEnvironment::withEndpoint(ProbeTestEnvironment::testDatabaseUrl(), '127.0.0.1', $proxy->port), \dirname(__DIR__, 4));

            $started = microtime(true);
            $outcome = $lookup->lookup(hash('sha256', $plaintext), self::ALLOWANCE);
            $elapsed = microtime(true) - $started;
        } finally {
            $proxy->stop();
        }

        self::assertTrue($outcome->isUnavailable());
        self::assertSame('ProcessTimedOutException', $outcome->reason);
        self::assertEqualsWithDelta(self::ALLOWANCE, $elapsed, 0.3);
        self::assertSame([], RunningProcesses::lookupChildren());
    }

    public function testADelayedConnectionStillVerifies(): void
    {
        $plaintext = $this->rows->key($this->rows->user());
        $upstream = parse_url(ProbeTestEnvironment::testDatabaseUrl());
        \assert(\is_array($upstream) && isset($upstream['host']));
        $proxy = FixtureProcess::start('tcp-proxy.php', [\sprintf('--upstream=%s:%d', $upstream['host'], $upstream['port'] ?? 5432), '--delay=0.7']);
        try {
            $lookup = new KeyLookupProcess(ProbeTestEnvironment::withEndpoint(ProbeTestEnvironment::testDatabaseUrl(), '127.0.0.1', $proxy->port), \dirname(__DIR__, 4));

            $started = microtime(true);
            $outcome = $lookup->lookup(hash('sha256', $plaintext), self::ALLOWANCE);
            $elapsed = microtime(true) - $started;
        } finally {
            $proxy->stop();
        }

        self::assertTrue($outcome->isVerified());
        self::assertGreaterThan(0.7, $elapsed);
    }

    public function testTheBoundedRedisClientTimesOutOnAServerThatNeverAnswers(): void
    {
        $blackHole = FixtureProcess::start('black-hole-socket.php');
        try {
            $connection = BoundedRedisConnection::connect(\sprintf('redis://127.0.0.1:%d', $blackHole->port), 0.25);
            $started = microtime(true);
            try {
                $connection->command('ping', 'PING');
                self::fail('a PING no server answers must time out');
            } catch (RedisOperationFailed $e) {
                self::assertSame('ping', $e->operation);
                self::assertEqualsWithDelta(0.25, microtime(true) - $started, 0.2, 'the deadline is the per-operation timeout');
            } finally {
                $connection->close();
            }
        } finally {
            $blackHole->stop();
        }
    }
}
