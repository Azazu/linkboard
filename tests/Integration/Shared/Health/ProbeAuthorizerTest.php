<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Health;

use App\Shared\Health\KeyLookupProcess;
use App\Shared\Health\ProbeAuthorizer;
use App\Shared\Health\ProbeMemory;
use App\Tests\Support\CommittedProbeKeys;
use App\Tests\Support\FixtureProcess;
use App\Tests\Support\ProbeTestEnvironment;
use App\Tests\Support\SpyLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The whole authorization against real PostgreSQL and Redis (design decision
 * 9): the refusal matrix, the bounded failure modes, and the memory that lets
 * a recently verified monitor through a database outage — including the
 * admitted case of a denial Redis could not record.
 */
#[CoversClass(ProbeAuthorizer::class)]
final class ProbeAuthorizerTest extends TestCase
{
    private CommittedProbeKeys $rows;
    private SpyLogger $logger;
    private \Redis $redis;
    /** @var list<string> */
    private array $hashes = [];

    protected function setUp(): void
    {
        $this->rows = CommittedProbeKeys::connect(ProbeTestEnvironment::testDatabaseUrl());
        $this->logger = new SpyLogger();
        $this->redis = new \Redis();
        $parts = parse_url(ProbeTestEnvironment::redisUrl());
        \assert(\is_array($parts) && isset($parts['host']));
        $this->redis->connect($parts['host'], $parts['port'] ?? 6379, 2.0);
    }

    protected function tearDown(): void
    {
        $this->rows->cleanup();
        foreach ($this->hashes as $hash) {
            $this->redis->del('probe-auth:gen:'.hash('sha256', $hash), 'probe-auth:v:'.hash('sha256', $hash));
        }
    }

    public function testAnAdminKeyIsAuthorizedAndRemembered(): void
    {
        $plaintext = $this->key();

        self::assertTrue($this->authorizer()->authorize('Bearer '.$plaintext));

        self::assertTrue($this->redis->exists($this->valueKey($plaintext)) > 0);
        self::assertSame([], $this->logger->records);
        self::assertNull($this->rows->lastUsedAt($plaintext), 'the verification never counts as a use');
    }

    public function testEveryOtherKeyIsRefusedAndTheDenialRecorded(): void
    {
        $cases = [
            'non-admin' => $this->rows->key($this->rows->user(admin: false)),
            'blocked admin' => $this->rows->key($this->rows->user(blocked: true)),
            'revoked' => $this->rows->key($this->rows->user(), revoked: true),
            'expired' => $this->rows->key($this->rows->user(), new \DateTimeImmutable('-1 minute')),
            'unknown' => 'lb_'.str_repeat('Q', 40),
        ];
        $authorizer = $this->authorizer();

        foreach ($cases as $case => $plaintext) {
            $this->hashes[] = hash('sha256', $plaintext);
            self::assertFalse($authorizer->authorize('Bearer '.$plaintext), $case);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $this->redis->get($this->tokenKey($plaintext)), "$case: the denial token is set");
            self::assertSame(0, $this->redis->exists($this->valueKey($plaintext)), "$case: nothing remembered");
        }
        self::assertSame([], $this->logger->records);
    }

    public function testAClosedPortIsRefusedFastWithAWarning(): void
    {
        $plaintext = $this->key();
        $authorizer = $this->authorizer(ProbeTestEnvironment::closedDatabaseUrl());

        $started = microtime(true);
        self::assertFalse($authorizer->authorize('Bearer '.$plaintext));

        self::assertLessThan(3.0, microtime(true) - $started);
        $warnings = $this->logger->withMessage('deep probe authorization: database unavailable');
        self::assertCount(1, $warnings);
        self::assertSame('PDOException', $warnings[0]['context']['reason']);
        $this->logger->assertNothingContains($plaintext);
        $this->logger->assertNothingContains(hash('sha256', $plaintext));
    }

    public function testAStalledLookupIsRefusedWithinTheBudget(): void
    {
        $plaintext = $this->key();
        $this->rows->lockApiKeys();
        try {
            $started = microtime(true);
            self::assertFalse($this->authorizer()->authorize('Bearer '.$plaintext));
            $elapsed = microtime(true) - $started;
        } finally {
            $this->rows->unlock();
        }

        self::assertLessThan(3.0, $elapsed);
        self::assertSame('ProcessTimedOutException', $this->logger->withMessage('deep probe authorization: database unavailable')[0]['context']['reason']);
    }

    public function testARememberedMonitorIsAuthorizedThroughADatabaseOutage(): void
    {
        $plaintext = $this->key();
        self::assertTrue($this->authorizer()->authorize('Bearer '.$plaintext), 'verified while the database is up');

        self::assertTrue($this->authorizer(ProbeTestEnvironment::closedDatabaseUrl())->authorize('Bearer '.$plaintext), 'remembered through the outage');
        self::assertCount(1, $this->logger->withMessage('deep probe authorization: database unavailable'));
    }

    public function testARememberedMonitorIsAuthorizedThroughAStalledLookup(): void
    {
        // The reserved Redis allowance: the database phase spends its whole 2.5 s
        // on the lock, and the consultation still runs on its own share.
        $plaintext = $this->key();
        self::assertTrue($this->authorizer()->authorize('Bearer '.$plaintext));

        $this->rows->lockApiKeys();
        try {
            $started = microtime(true);
            self::assertTrue($this->authorizer()->authorize('Bearer '.$plaintext), 'remembered through the stalled lookup');
            $elapsed = microtime(true) - $started;
        } finally {
            $this->rows->unlock();
        }

        self::assertLessThan(ProbeAuthorizer::DATABASE_ALLOWANCE + ProbeAuthorizer::REDIS_ALLOWANCE + 0.5, $elapsed);
    }

    public function testADenialWhileTheDatabaseIsUpIsNotUndoneByALaterOutage(): void
    {
        $plaintext = $this->key();
        self::assertTrue($this->authorizer()->authorize('Bearer '.$plaintext));
        $this->rows->revoke($plaintext);
        self::assertFalse($this->authorizer()->authorize('Bearer '.$plaintext), 'the revocation is observed at once');

        self::assertFalse($this->authorizer(ProbeTestEnvironment::closedDatabaseUrl())->authorize('Bearer '.$plaintext), 'and the outage does not restore access');
    }

    public function testADenialRedisCouldNotRecordLeavesTheEarlierMemoryTheAdmittedCase(): void
    {
        $plaintext = $this->key();
        self::assertTrue($this->authorizer()->authorize('Bearer '.$plaintext));
        $this->rows->revoke($plaintext);

        $unreachableMemory = $this->authorizer(redisUrl: ProbeTestEnvironment::closedRedisUrl());
        self::assertFalse($unreachableMemory->authorize('Bearer '.$plaintext), 'the revocation itself is refused');
        $warnings = $this->logger->withMessage('deep probe authorization: memory unavailable');
        self::assertCount(1, $warnings);
        self::assertSame('connect', $warnings[0]['context']['operation']);

        self::assertTrue($this->redis->exists($this->valueKey($plaintext)) > 0, 'the earlier verification survives: the accepted staleness, bounded by its age');
        self::assertTrue($this->authorizer(ProbeTestEnvironment::closedDatabaseUrl())->authorize('Bearer '.$plaintext), 'the admitted case, asserted as such');
    }

    public function testARedisWhoseConnectNeverCompletesStillLetsTheDatabaseAnswer(): void
    {
        $plaintext = $this->key();
        $listener = FixtureProcess::start('full-backlog-listener.php');
        try {
            $started = microtime(true);
            self::assertTrue($this->authorizer(redisUrl: \sprintf('redis://127.0.0.1:%d', $listener->port))->authorize('Bearer '.$plaintext), 'verified for this request');
            $elapsed = microtime(true) - $started;
        } finally {
            $listener->stop();
        }

        self::assertLessThan(1.5, $elapsed);
        self::assertSame('connect', $this->logger->withMessage('deep probe authorization: memory unavailable')[0]['context']['operation']);
        self::assertSame(0, $this->redis->exists($this->valueKey($plaintext)), 'not remembered anywhere');
    }

    private function key(): string
    {
        $plaintext = $this->rows->key($this->rows->user());
        $this->hashes[] = hash('sha256', $plaintext);

        return $plaintext;
    }

    private function authorizer(?string $databaseUrl = null, ?string $redisUrl = null): ProbeAuthorizer
    {
        return new ProbeAuthorizer(
            new KeyLookupProcess($databaseUrl ?? ProbeTestEnvironment::testDatabaseUrl(), \dirname(__DIR__, 4)),
            new ProbeMemory($redisUrl ?? ProbeTestEnvironment::redisUrl()),
            new MockClock(),
            $this->logger,
        );
    }

    private function tokenKey(string $plaintext): string
    {
        return 'probe-auth:gen:'.hash('sha256', hash('sha256', $plaintext));
    }

    private function valueKey(string $plaintext): string
    {
        return 'probe-auth:v:'.hash('sha256', hash('sha256', $plaintext));
    }
}
