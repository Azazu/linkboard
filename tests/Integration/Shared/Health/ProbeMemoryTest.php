<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Health;

use App\Shared\Health\KeyLookupProcess;
use App\Shared\Health\MemoryUnavailable;
use App\Shared\Health\ProbeAuthorizer;
use App\Shared\Health\ProbeMemory;
use App\Tests\Support\CommittedProbeKeys;
use App\Tests\Support\FixtureProcess;
use App\Tests\Support\InterleavingLookup;
use App\Tests\Support\ProbeTestEnvironment;
use App\Tests\Support\SpyLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The Redis memory against real Redis (design decision 9): the compare-and-set
 * on the denial token, the interleavings established by the real mechanism
 * (database snapshot, then revocation, then a denial, then the stale write),
 * the verification-age bound, and the per-command timeouts against a fake
 * Redis and a listener whose accept queue is full.
 */
#[CoversClass(ProbeMemory::class)]
final class ProbeMemoryTest extends TestCase
{
    private ProbeMemory $memory;
    private \Redis $redis;
    private string $hash;

    protected function setUp(): void
    {
        $this->memory = new ProbeMemory(ProbeTestEnvironment::redisUrl());
        $this->redis = new \Redis();
        $parts = parse_url(ProbeTestEnvironment::redisUrl());
        \assert(\is_array($parts) && isset($parts['host']));
        $this->redis->connect($parts['host'], $parts['port'] ?? 6379, 2.0);
        $this->hash = hash('sha256', 'lb_'.bin2hex(random_bytes(20)));
    }

    protected function tearDown(): void
    {
        $this->memory->close();
        $this->redis->del($this->tokenKey(), $this->valueKey());
    }

    public function testRememberStoresOnlyWhenTheTokenIsTheOneReadBefore(): void
    {
        $this->memory->connect();
        $now = new \DateTimeImmutable();

        self::assertSame('', $this->memory->token($this->hash), 'no denial yet: an absent token reads as the empty string');
        self::assertTrue($this->memory->remember($this->hash, '', null, $now), 'absent read, absent key: stored');
        self::assertTrue($this->memory->consult($this->hash, $now));

        $this->memory->deny($this->hash);
        self::assertFalse($this->memory->consult($this->hash, $now), 'a denial drops the memory');
        $token = $this->memory->token($this->hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        self::assertFalse($this->memory->remember($this->hash, '', null, $now), 'a read that predates the denial is refused');
        self::assertFalse($this->memory->remember($this->hash, 'not-the-token', null, $now));
        self::assertFalse($this->memory->consult($this->hash, $now));
        self::assertTrue($this->memory->remember($this->hash, $token, null, $now), 'a read after the denial is current');
        self::assertTrue($this->memory->consult($this->hash, $now));
    }

    public function testTwoDenialsYieldTwoDifferentTokensAndBothKeysExpire(): void
    {
        $this->memory->connect();
        $this->memory->deny($this->hash);
        $first = $this->memory->token($this->hash);
        $this->memory->deny($this->hash);
        $second = $this->memory->token($this->hash);

        self::assertNotSame($first, $second, 'a denial token is replaced, never incremented');
        $this->memory->remember($this->hash, $second, null, new \DateTimeImmutable());
        foreach ([$this->tokenKey(), $this->valueKey()] as $key) {
            $ttl = $this->redis->pttl($key);
            self::assertGreaterThan(0, $ttl, $key);
            self::assertLessThanOrEqual(ProbeMemory::TTL_SECONDS * 1000, $ttl, $key);
        }
    }

    public function testAVerificationThatRacedARevocationIsNotStored(): void
    {
        // Established by the mechanism, not by timestamps: request A (the real
        // authorizer) reads the token and runs its lookup while the key is
        // valid; before A continues, the key is revoked and request B observes
        // the denial; A's verification then reaches the memory — and is refused.
        $rows = CommittedProbeKeys::connect(ProbeTestEnvironment::testDatabaseUrl());
        try {
            $plaintext = $rows->key($rows->user());
            $hash = hash('sha256', $plaintext);
            $lookup = new KeyLookupProcess(ProbeTestEnvironment::testDatabaseUrl(), \dirname(__DIR__, 4));
            $authorizerB = new ProbeAuthorizer($lookup, new ProbeMemory(ProbeTestEnvironment::redisUrl()), new MockClock(), new SpyLogger());
            $paused = new InterleavingLookup($lookup, static function () use ($rows, $plaintext, $authorizerB): void {
                $rows->revoke($plaintext);
                self::assertFalse($authorizerB->authorize('Bearer '.$plaintext), 'B observes the revocation');
            });
            $authorizerA = new ProbeAuthorizer($paused, new ProbeMemory(ProbeTestEnvironment::redisUrl()), new MockClock(), new SpyLogger());

            self::assertTrue($authorizerA->authorize('Bearer '.$plaintext), 'A saw the key as valid and is honoured for its own request');
            self::assertTrue($paused->outcome?->isVerified());

            $this->memory->connect();
            self::assertFalse($this->memory->consult($hash, new \DateTimeImmutable()), 'the stale verification was not stored: a later outage refuses the monitor');
            self::assertSame(0, $this->redis->exists('probe-auth:v:'.hash('sha256', $hash)));
            $this->redis->del('probe-auth:gen:'.hash('sha256', $hash));
        } finally {
            $rows->cleanup();
        }
    }

    public function testATokenThatExpiredBetweenTheReadAndTheDenialCannotBeMistakenForNone(): void
    {
        // A denial left token X; A read X; X expired (DEL stands for the TTL);
        // the key is revoked; B's denial creates Y ≠ X; A's write with X refuses.
        $rows = CommittedProbeKeys::connect(ProbeTestEnvironment::testDatabaseUrl());
        try {
            $plaintext = $rows->key($rows->user());
            $hash = hash('sha256', $plaintext);
            $tokenKey = 'probe-auth:gen:'.hash('sha256', $hash);
            $this->memory->connect();
            $this->memory->deny($hash);
            $tokenX = $this->memory->token($hash);
            $this->memory->close();

            $lookup = new KeyLookupProcess(ProbeTestEnvironment::testDatabaseUrl(), \dirname(__DIR__, 4));
            $authorizerB = new ProbeAuthorizer($lookup, new ProbeMemory(ProbeTestEnvironment::redisUrl()), new MockClock(), new SpyLogger());
            $redis = $this->redis;
            $tokenY = null;
            $paused = new InterleavingLookup($lookup, static function () use ($rows, $plaintext, $authorizerB, $redis, $tokenKey, &$tokenY): void {
                $redis->del($tokenKey); // X expires
                $rows->revoke($plaintext);
                self::assertFalse($authorizerB->authorize('Bearer '.$plaintext));
                $tokenY = $redis->get($tokenKey);
            });
            $authorizerA = new ProbeAuthorizer($paused, new ProbeMemory(ProbeTestEnvironment::redisUrl()), new MockClock(), new SpyLogger());

            self::assertTrue($authorizerA->authorize('Bearer '.$plaintext));
            self::assertNotSame('', $tokenX);
            self::assertIsString($tokenY);
            self::assertNotSame($tokenX, $tokenY, 'a recreated token is a fresh random value');
            $this->memory->connect();
            self::assertFalse($this->memory->consult($hash, new \DateTimeImmutable()), 'A read X, the current token is Y: the stale verification was refused');
            $this->redis->del($tokenKey, 'probe-auth:v:'.hash('sha256', $hash));
        } finally {
            $rows->cleanup();
        }
    }

    public function testConsultBoundsTheAgeFromTheVerificationAndChecksTheKeyExpiry(): void
    {
        $this->memory->connect();
        $now = new \DateTimeImmutable('2026-09-12T12:00:00+00:00');

        self::assertTrue($this->memory->remember($this->hash, '', null, $now->modify('-301 seconds')));
        self::assertGreaterThan(0, $this->redis->pttl($this->valueKey()), 'the TTL has not run out');
        self::assertFalse($this->memory->consult($this->hash, $now), 'a verification 301 s old is not consulted whatever its TTL');

        self::assertTrue($this->memory->remember($this->hash, '', null, $now->modify('-299 seconds')));
        self::assertTrue($this->memory->consult($this->hash, $now));

        self::assertTrue($this->memory->remember($this->hash, '', $now->modify('-1 second'), $now));
        self::assertFalse($this->memory->consult($this->hash, $now), 'an expired key is not authorized from memory');

        self::assertTrue($this->memory->remember($this->hash, '', $now->modify('+1 hour'), $now));
        self::assertTrue($this->memory->consult($this->hash, $now));

        $this->redis->set($this->valueKey(), 'not json');
        self::assertFalse($this->memory->consult($this->hash, $now), 'an unexpected value is not a verification');
    }

    public function testEverySlowOperationStillCompletesWithinTheAllowance(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'fake-redis');
        \assert(\is_string($log));
        $fake = FixtureProcess::start('fake-redis-server.php', ['--response-delay=0.2', '--command-log='.$log]);
        try {
            $memory = new ProbeMemory(\sprintf('redis://:secret@127.0.0.1:%d', $fake->port));
            $started = microtime(true);
            $memory->connect();
            self::assertSame('', $memory->token($this->hash));
            self::assertFalse($memory->consult($this->hash, new \DateTimeImmutable()));
            $elapsed = microtime(true) - $started;
            $memory->close();
        } finally {
            $fake->stop();
        }

        self::assertGreaterThanOrEqual(0.6, $elapsed, 'three delayed commands');
        self::assertLessThan(ProbeAuthorizer::REDIS_ALLOWANCE, $elapsed);
        self::assertSame(['AUTH', 'GET', 'GET'], self::answered($log));
        unlink($log);
    }

    public function testASlowPostLookupCommandTimesOutAtItsShareAfterTheEarlierOnesPassed(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'fake-redis');
        \assert(\is_string($log));
        // AUTH = command 1, the token GET = 2, the consultation = 3 (connect is no command)
        $fake = FixtureProcess::start('fake-redis-server.php', ['--response-delay=0.5', '--slow-from-command=3', '--command-log='.$log]);
        try {
            $memory = new ProbeMemory(\sprintf('redis://:secret@127.0.0.1:%d', $fake->port));
            $memory->connect();
            self::assertSame('', $memory->token($this->hash));
            $started = microtime(true);
            try {
                $memory->consult($this->hash, new \DateTimeImmutable());
                self::fail('the consultation must time out');
            } catch (MemoryUnavailable $e) {
                $elapsed = microtime(true) - $started;
                self::assertSame('consult', $e->operation);
            }
        } finally {
            $fake->stop();
        }

        self::assertGreaterThanOrEqual(ProbeMemory::OPERATION_TIMEOUT, $elapsed);
        self::assertLessThan(0.5, $elapsed, 'the timeout fires before the fake answers');
        self::assertSame(['AUTH', 'GET'], self::answered($log), 'the first two commands were answered');
        self::assertSame(['AUTH', 'GET', 'GET'], self::received($log), 'the consultation was received but not answered in time');
        unlink($log);
    }

    public function testAReplyArrivingInPiecesEndsAtTheOperationDeadlineAllTheSame(): void
    {
        // A server that keeps making progress and never finishes: one byte
        // every 100 ms arrives well inside any per-read timeout, so that kind
        // of timeout never fires — a deadline taken when the operation started
        // does (Gate 2 round 1, finding 2). This 39-byte reply would take about
        // four seconds; the operation's share of the allowance is a quarter of one.
        $token = bin2hex(random_bytes(16));
        $fake = FixtureProcess::start('fake-redis-server.php', ['--slow-from-command=2', '--fragment-bytes=1', '--fragment-delay=0.1', '--get-value='.$token]);
        try {
            $memory = new ProbeMemory(\sprintf('redis://:secret@127.0.0.1:%d', $fake->port));
            $memory->connect();
            $started = microtime(true);
            try {
                $memory->token($this->hash);
                self::fail('a dribbled reply must end at the operation deadline');
            } catch (MemoryUnavailable $e) {
                $elapsed = microtime(true) - $started;
                self::assertSame('token', $e->operation);
            }
            self::assertTrue($fake->isRunning(), 'the server was still happily sending when the deadline ended it');
            $memory->close();
        } finally {
            $fake->stop();
        }

        self::assertGreaterThanOrEqual(ProbeMemory::OPERATION_TIMEOUT, $elapsed);
        self::assertLessThan(3 * ProbeMemory::OPERATION_TIMEOUT, $elapsed, 'the whole Redis phase stays inside its allowance');
    }

    public function testADribbledConsultationEndsAtItsShareAfterTheEarlierCommandsPassed(): void
    {
        $remembered = json_encode([
            'gen' => '',
            'exp' => null,
            'verified_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
        $fake = FixtureProcess::start('fake-redis-server.php', ['--slow-from-command=3', '--fragment-bytes=1', '--fragment-delay=0.1', '--get-value='.$remembered]);
        try {
            $memory = new ProbeMemory(\sprintf('redis://:secret@127.0.0.1:%d', $fake->port));
            $started = microtime(true);
            $memory->connect();
            self::assertNotSame('', $memory->token($this->hash), 'the pre-lookup read completed');
            try {
                $memory->consult($this->hash, new \DateTimeImmutable());
                self::fail('a dribbled consultation must end at its share');
            } catch (MemoryUnavailable $e) {
                $elapsed = microtime(true) - $started;
                self::assertSame('consult', $e->operation);
            }
            $memory->close();
        } finally {
            $fake->stop();
        }

        self::assertLessThan(ProbeAuthorizer::REDIS_ALLOWANCE, $elapsed, 'connect, AUTH, the token read and the consultation together stay inside the allowance');
    }

    public function testAConnectThatNeverCompletesTimesOutAtItsShare(): void
    {
        $listener = FixtureProcess::start('full-backlog-listener.php');
        try {
            $memory = new ProbeMemory(\sprintf('redis://:secret@127.0.0.1:%d', $listener->port));
            $started = microtime(true);
            try {
                $memory->connect();
                self::fail('a connect the kernel never completes must time out');
            } catch (MemoryUnavailable $e) {
                $elapsed = microtime(true) - $started;
                self::assertSame('connect', $e->operation);
            }
        } finally {
            $listener->stop();
        }

        self::assertGreaterThanOrEqual(ProbeMemory::OPERATION_TIMEOUT, $elapsed);
        self::assertLessThan(0.5, $elapsed);
    }

    /** @return list<string> */
    private static function answered(string $log): array
    {
        return self::logColumn($log, 'answered');
    }

    /** @return list<string> */
    private static function received(string $log): array
    {
        return self::logColumn($log, 'received');
    }

    /** @return list<string> */
    private static function logColumn(string $log, string $kind): array
    {
        $commands = [];
        foreach (file($log, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$lineKind, $command] = explode(' ', $line);
            if ($lineKind === $kind) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    private function tokenKey(): string
    {
        return 'probe-auth:gen:'.hash('sha256', $this->hash);
    }

    private function valueKey(): string
    {
        return 'probe-auth:v:'.hash('sha256', $this->hash);
    }
}
