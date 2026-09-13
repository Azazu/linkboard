<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Health\ProbeAuthorizer;
use App\Tests\Support\CommittedProbeKeys;
use App\Tests\Support\FixtureProcess;
use App\Tests\Support\ProbeTestEnvironment;
use App\Tests\Support\ProdHealthRequest;
use App\Tests\Support\RunningProcesses;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Spec health-check, the `prod` deep probe end to end: every refusal is one and
 * the same 404, an admin key gets the probe's own report, and every way the
 * database or Redis can misbehave still answers within the 5-second bound.
 *
 * Rows are written through a plain PDO and committed: the prod kernel runs in
 * its own process (tests/Support/ProdHealthRequest) and the lookup in another,
 * so the suite's rolled-back transaction is invisible to both. The database is
 * DATABASE_URL as is — the `app` database, migrated by `make migrate`.
 */
#[CoversNothing]
final class HealthDeepProdTest extends TestCase
{
    private CommittedProbeKeys $rows;
    private \Redis $redis;
    /** @var list<string> */
    private array $hashes = [];

    protected function setUp(): void
    {
        $this->rows = CommittedProbeKeys::connect(ProbeTestEnvironment::appDatabaseUrl());
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

    public function testEveryUnauthorizedRequestGetsOneAndTheSameProblemBody(): void
    {
        $keys = [
            'non-admin key' => $this->rows->key($this->rows->user(admin: false)),
            'revoked admin key' => $this->rows->key($this->rows->user(), revoked: true),
            'expired admin key' => $this->rows->key($this->rows->user(), new \DateTimeImmutable('-1 minute')),
            'blocked admin key' => $this->rows->key($this->rows->user(blocked: true)),
        ];
        $headers = [
            'no header' => null,
            'an admin JWT' => 'Bearer eyJhbGciOiJSUzI1NiJ9.not-checked-here.admin',
            'an unknown key' => 'Bearer lb_'.str_repeat('Z', 40),
        ];
        foreach ($keys as $case => $plaintext) {
            $headers[$case] = 'Bearer '.$plaintext;
            $this->hashes[] = hash('sha256', $plaintext);
        }

        $bodies = [];
        foreach ($headers as $case => $header) {
            $response = ProdHealthRequest::send($header);

            self::assertSame(404, $response->status, $case);
            self::assertSame('application/problem+json', $response->contentType, $case);
            self::assertStringContainsString('no-store', (string) $response->cacheControl, $case);
            $problem = json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($problem, $case);
            self::assertSame(404, $problem['status'], $case);
            foreach (['type', 'title', 'detail'] as $member) {
                self::assertArrayHasKey($member, $problem, $case);
            }
            $bodies[$case] = $response->body;
        }

        self::assertCount(1, array_unique(array_values($bodies)), 'the refusal reveals nothing: '.json_encode($bodies, \JSON_THROW_ON_ERROR));
        foreach ($keys as $case => $plaintext) {
            self::assertNull($this->rows->lastUsedAt($plaintext), "$case: a refusal never counts as a use");
        }
    }

    public function testAnAdminKeyGetsTheProbeReport(): void
    {
        $plaintext = $this->key();

        $response = ProdHealthRequest::send('Bearer '.$plaintext);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('{"status":"ok","checks":{"database":"ok","redis":"ok"}}', $response->body);
        self::assertStringContainsString('no-store', (string) $response->cacheControl);
        self::assertNull($this->rows->lastUsedAt($plaintext), 'the verification never counts as a use');
    }

    public function testARefusedDatabaseIsAnswered(): void
    {
        $plaintext = $this->key();

        $response = ProdHealthRequest::send('Bearer '.$plaintext, ['DATABASE_URL' => ProbeTestEnvironment::closedDatabaseUrl()]);

        self::assertSame(404, $response->status);
        self::assertLessThan(1.0, $response->elapsed);
        self::assertSame('PDOException', $response->withMessage('deep probe authorization: database unavailable')[0]['context']['reason']);
        $response->assertNothingContains($plaintext);
        $response->assertNothingContains(hash('sha256', $plaintext));
    }

    public function testAConnectionThatNeverCompletesIsAnsweredWithinTheBound(): void
    {
        $plaintext = $this->key();
        $blackHole = FixtureProcess::start('black-hole-socket.php');
        try {
            $response = ProdHealthRequest::send('Bearer '.$plaintext, [
                'DATABASE_URL' => ProbeTestEnvironment::withEndpoint(ProbeTestEnvironment::appDatabaseUrl(), '127.0.0.1', $blackHole->port),
            ]);
        } finally {
            $blackHole->stop();
        }

        self::assertSame(404, $response->status);
        self::assertLessThan(3.5, $response->elapsed);
    }

    public function testAStalledLookupIsAnsweredAtTheAllowance(): void
    {
        $plaintext = $this->key();
        $this->rows->lockApiKeys();
        try {
            $response = ProdHealthRequest::send('Bearer '.$plaintext);
        } finally {
            $this->rows->unlock();
        }

        self::assertSame(404, $response->status);
        self::assertGreaterThan(ProbeAuthorizer::DATABASE_ALLOWANCE, $response->elapsed);
        self::assertLessThan(4.0, $response->elapsed);
        self::assertSame('ProcessTimedOutException', $response->withMessage('deep probe authorization: database unavailable')[0]['context']['reason']);
    }

    public function testADelayedConnectionAndAStalledLookupTogetherStayWithinTheBound(): void
    {
        $plaintext = $this->key();
        $proxy = $this->databaseProxy(['--delay=0.7']);
        $this->rows->lockApiKeys();
        try {
            $response = ProdHealthRequest::send('Bearer '.$plaintext, [
                'DATABASE_URL' => ProbeTestEnvironment::withEndpoint(ProbeTestEnvironment::appDatabaseUrl(), '127.0.0.1', $proxy->port),
            ]);
        } finally {
            $this->rows->unlock();
            $proxy->stop();
        }

        self::assertSame(404, $response->status);
        self::assertLessThan(5.0, $response->elapsed);
    }

    public function testALostResponseIsAnsweredWithinTheBoundAndLeavesNoChild(): void
    {
        // The case no in-process PostgreSQL client passes: the handshake
        // completed, the query went out, the answer never came back.
        $plaintext = $this->key();
        $proxy = $this->databaseProxy(['--stall-after-responses=1']);
        try {
            $response = ProdHealthRequest::send('Bearer '.$plaintext, [
                'DATABASE_URL' => ProbeTestEnvironment::withEndpoint(ProbeTestEnvironment::appDatabaseUrl(), '127.0.0.1', $proxy->port),
            ]);
        } finally {
            $proxy->stop();
        }

        self::assertSame(404, $response->status);
        self::assertLessThan(5.0, $response->elapsed);
        self::assertSame([], RunningProcesses::lookupChildren(), 'the lookup child died with its parent');
    }

    public function testASlowConsultationTimesOutAtItsShareAfterTheEarlierCommandsPassed(): void
    {
        $plaintext = $this->key();
        $log = tempnam(sys_get_temp_dir(), 'fake-redis');
        \assert(\is_string($log));
        // AUTH = command 1, the token read = 2, the consultation = 3
        $fake = FixtureProcess::start('fake-redis-server.php', ['--response-delay=0.5', '--slow-from-command=3', '--command-log='.$log]);
        try {
            $response = ProdHealthRequest::send('Bearer '.$plaintext, [
                'DATABASE_URL' => ProbeTestEnvironment::closedDatabaseUrl(),
                'REDIS_URL' => \sprintf('redis://:secret@127.0.0.1:%d', $fake->port),
            ]);
        } finally {
            $fake->stop();
        }

        self::assertSame(404, $response->status);
        self::assertLessThan(5.0, $response->elapsed);
        // The client gave up on the consultation at its 0.25-second share; the
        // fake answers it half a second in, so what the log proves is the order:
        // the authentication and the token read completed before it, and the
        // warning — which a merely empty memory would not produce — names it.
        self::assertSame(['AUTH', 'GET', 'GET'], \array_slice(self::logColumn($log, 'received'), 0, 3), 'the consultation was sent');
        self::assertSame(['AUTH', 'GET'], \array_slice(self::logColumn($log, 'answered'), 0, 2), 'the authentication and the token read completed');
        self::assertSame('consult', $response->withMessage('deep probe authorization: memory unavailable')[0]['context']['operation']);
        unlink($log);
    }

    public function testASlowButAnsweringRedisStillLetsTheMemoryDecide(): void
    {
        $plaintext = $this->key();
        $log = tempnam(sys_get_temp_dir(), 'fake-redis');
        \assert(\is_string($log));

        $withoutMemory = FixtureProcess::start('fake-redis-server.php', ['--response-delay=0.2', '--command-log='.$log]);
        try {
            $refused = ProdHealthRequest::send('Bearer '.$plaintext, [
                'DATABASE_URL' => ProbeTestEnvironment::closedDatabaseUrl(),
                'REDIS_URL' => \sprintf('redis://:secret@127.0.0.1:%d', $withoutMemory->port),
            ]);
        } finally {
            $withoutMemory->stop();
        }

        self::assertSame(404, $refused->status, 'nothing remembered: refused');
        self::assertSame(['AUTH', 'GET', 'GET'], self::logColumn($log, 'answered'), 'every command answered, the consultation included');
        self::assertSame([], $refused->withMessage('deep probe authorization: memory unavailable'));
        unlink($log);

        $log = tempnam(sys_get_temp_dir(), 'fake-redis');
        \assert(\is_string($log));
        $remembered = json_encode([
            'gen' => '',
            'exp' => null,
            'verified_at' => (new \DateTimeImmutable('-1 minute'))->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
        $withMemory = FixtureProcess::start('fake-redis-server.php', ['--response-delay=0.2', '--command-log='.$log, '--get-value='.$remembered]);
        try {
            $authorized = ProdHealthRequest::send('Bearer '.$plaintext, [
                'DATABASE_URL' => ProbeTestEnvironment::closedDatabaseUrl(),
                'REDIS_URL' => \sprintf('redis://:secret@127.0.0.1:%d', $withMemory->port),
            ]);
        } finally {
            $withMemory->stop();
        }

        self::assertSame(503, $authorized->status, $authorized->body);
        self::assertSame('{"status":"fail","checks":{"database":"fail","redis":"ok"}}', $authorized->body, 'the probe runs and reports the outage it exists to report');
        self::assertLessThan(5.0, $authorized->elapsed);
        // AUTH, the token read, the consultation — then the probe's own Redis check
        self::assertSame(['AUTH', 'GET', 'GET', 'AUTH', 'PING'], self::logColumn($log, 'answered'));
        unlink($log);
    }

    public function testAConsultationDribbledOutByteByByteStillAnswersWithinTheBound(): void
    {
        // Without a deadline over the whole operation this reply — one byte
        // every 100 ms, each arriving inside any per-read timeout — would hold
        // the request for some ten seconds.
        $plaintext = $this->key();
        $remembered = json_encode([
            'gen' => '',
            'exp' => null,
            'verified_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
        $fake = FixtureProcess::start('fake-redis-server.php', ['--slow-from-command=3', '--fragment-bytes=1', '--fragment-delay=0.1', '--get-value='.$remembered]);
        try {
            $response = ProdHealthRequest::send('Bearer '.$plaintext, [
                'DATABASE_URL' => ProbeTestEnvironment::closedDatabaseUrl(),
                'REDIS_URL' => \sprintf('redis://:secret@127.0.0.1:%d', $fake->port),
            ]);
        } finally {
            $fake->stop();
        }

        self::assertSame(404, $response->status);
        self::assertLessThan(5.0, $response->elapsed);
        self::assertSame('consult', $response->withMessage('deep probe authorization: memory unavailable')[0]['context']['operation']);
    }

    public function testARedisWhoseConnectNeverCompletesStillLetsTheDatabaseAnswer(): void
    {
        $plaintext = $this->key();
        $listener = FixtureProcess::start('full-backlog-listener.php');
        try {
            $response = ProdHealthRequest::send('Bearer '.$plaintext, [
                'REDIS_URL' => \sprintf('redis://127.0.0.1:%d', $listener->port),
            ]);
        } finally {
            $listener->stop();
        }

        self::assertSame(503, $response->status, $response->body);
        self::assertSame('{"status":"fail","checks":{"database":"ok","redis":"fail"}}', $response->body, 'authorized by the database, and the probe reports the Redis it could not reach');
        self::assertSame('connect', $response->withMessage('deep probe authorization: memory unavailable')[0]['context']['operation']);
        self::assertSame(0, $this->redis->exists('probe-auth:v:'.hash('sha256', hash('sha256', $plaintext))), 'nothing was stored');
    }

    public function testARememberedMonitorIsToldAboutARefusedDatabase(): void
    {
        $plaintext = $this->key();
        self::assertSame(200, ProdHealthRequest::send('Bearer '.$plaintext)->status, 'verified while the database is up');

        $response = ProdHealthRequest::send('Bearer '.$plaintext, ['DATABASE_URL' => ProbeTestEnvironment::closedDatabaseUrl()]);

        self::assertSame(503, $response->status, $response->body);
        self::assertSame('{"status":"fail","checks":{"database":"fail","redis":"ok"}}', $response->body);
    }

    public function testARememberedMonitorGetsTheProbeReportWhileOnlyTheLookupStalls(): void
    {
        $plaintext = $this->key();
        self::assertSame(200, ProdHealthRequest::send('Bearer '.$plaintext)->status);

        $this->rows->lockApiKeys();
        try {
            $response = ProdHealthRequest::send('Bearer '.$plaintext);
        } finally {
            $this->rows->unlock();
        }

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('{"status":"ok","checks":{"database":"ok","redis":"ok"}}', $response->body, 'the probe reports what it observes, not what the lookup suffered');
        self::assertLessThan(5.0, $response->elapsed);
    }

    public function testARevokedKeyIsRefusedAtOnceAndStaysRefusedThroughAnOutage(): void
    {
        $plaintext = $this->key();
        self::assertSame(200, ProdHealthRequest::send('Bearer '.$plaintext)->status);

        $this->rows->revoke($plaintext);
        self::assertSame(404, ProdHealthRequest::send('Bearer '.$plaintext)->status, 'the revocation is observed at once');

        $response = ProdHealthRequest::send('Bearer '.$plaintext, ['DATABASE_URL' => ProbeTestEnvironment::closedDatabaseUrl()]);
        self::assertSame(404, $response->status, 'and a later outage does not restore access');
    }

    private function key(): string
    {
        $plaintext = $this->rows->key($this->rows->user());
        $this->hashes[] = hash('sha256', $plaintext);

        return $plaintext;
    }

    /**
     * @param list<string> $arguments
     */
    private function databaseProxy(array $arguments): FixtureProcess
    {
        $upstream = parse_url(ProbeTestEnvironment::appDatabaseUrl());
        \assert(\is_array($upstream) && isset($upstream['host']));

        return FixtureProcess::start('tcp-proxy.php', [\sprintf('--upstream=%s:%d', $upstream['host'], $upstream['port'] ?? 5432), ...$arguments]);
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
}
