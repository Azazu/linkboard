<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Health;

use App\Shared\Health\KeyLookupInterface;
use App\Shared\Health\LookupOutcome;
use App\Shared\Health\MemoryUnavailable;
use App\Shared\Health\ProbeAuthorizer;
use App\Shared\Health\ProbeMemoryInterface;
use App\Tests\Support\SpyLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The authorizer's decision table with both phases faked (design decision 9):
 * which answer wins, what is written to the memory, and what is logged.
 */
#[CoversClass(ProbeAuthorizer::class)]
final class ProbeAuthorizerTest extends TestCase
{
    private const string KEY = 'lb_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmn';
    private const string HEADER = 'Bearer '.self::KEY;

    private FakeLookup $lookup;
    private FakeMemory $memory;
    private MockClock $clock;
    private SpyLogger $logger;
    private ProbeAuthorizer $authorizer;

    protected function setUp(): void
    {
        $this->lookup = new FakeLookup();
        $this->memory = new FakeMemory();
        $this->clock = new MockClock('2026-09-12T10:00:00+00:00');
        $this->logger = new SpyLogger();
        $this->authorizer = new ProbeAuthorizer($this->lookup, $this->memory, $this->clock, $this->logger);
    }

    public function testAVerifiedKeyIsAuthorizedAndRememberedWithTheTokenReadBefore(): void
    {
        $this->memory->token = 'tok-before';
        $this->lookup->outcome = LookupOutcome::verified(new \DateTimeImmutable('2030-01-01T00:00:00+00:00'));

        self::assertTrue($this->authorizer->authorize(self::HEADER));

        self::assertSame(['connect', 'token', 'remember', 'close'], $this->memory->calls);
        self::assertSame('tok-before', $this->memory->rememberedToken);
        self::assertSame('2030-01-01T00:00:00+00:00', $this->memory->rememberedExpiry?->format(\DATE_ATOM));
        self::assertSame('2026-09-12T10:00:00+00:00', $this->memory->rememberedVerifiedAt?->format(\DATE_ATOM));
        self::assertSame(hash('sha256', self::KEY), $this->lookup->hash, 'the lookup receives the hash, never the key');
        self::assertSame(ProbeAuthorizer::DATABASE_ALLOWANCE, $this->lookup->allowance);
        self::assertSame([], $this->logger->records);
    }

    public function testADeniedKeyIsRefusedAndTheDenialRecorded(): void
    {
        $this->lookup->outcome = LookupOutcome::denied();

        self::assertFalse($this->authorizer->authorize(self::HEADER));

        self::assertSame(['connect', 'token', 'deny', 'close'], $this->memory->calls);
        self::assertSame([], $this->logger->records, 'a denial is not a warning');
    }

    public function testAnUnavailableDatabaseConsultsTheMemory(): void
    {
        $this->lookup->outcome = LookupOutcome::unavailable('ProcessTimedOutException');
        $this->memory->consultAnswer = true;

        self::assertTrue($this->authorizer->authorize(self::HEADER));

        self::assertSame(['connect', 'token', 'consult', 'close'], $this->memory->calls);
        self::assertSame('2026-09-12T10:00:00+00:00', $this->memory->consultedAt?->format(\DATE_ATOM));
        $warnings = $this->logger->withMessage('deep probe authorization: database unavailable');
        self::assertCount(1, $warnings);
        self::assertSame(['reason' => 'ProcessTimedOutException'], $warnings[0]['context']);
        self::assertSame('warning', $warnings[0]['level']);
    }

    public function testAnUnavailableDatabaseWithNothingRememberedIsRefused(): void
    {
        $this->lookup->outcome = LookupOutcome::unavailable('PDOException');
        $this->memory->consultAnswer = false;

        self::assertFalse($this->authorizer->authorize(self::HEADER));
    }

    public function testAnUnavailableDatabaseAndAFailingMemoryIsRefusedWithBothWarnings(): void
    {
        $this->lookup->outcome = LookupOutcome::unavailable('PDOException');
        $this->memory->failOn = 'consult';

        self::assertFalse($this->authorizer->authorize(self::HEADER));

        self::assertCount(1, $this->logger->withMessage('deep probe authorization: database unavailable'));
        $memoryWarnings = $this->logger->withMessage('deep probe authorization: memory unavailable');
        self::assertCount(1, $memoryWarnings);
        self::assertSame('consult', $memoryWarnings[0]['context']['operation']);
        self::assertSame(\RedisException::class, $memoryWarnings[0]['context']['exception']);
    }

    public function testAMemoryFailingBeforeTheLookupStillHonoursAVerifiedAnswerWithoutRemembering(): void
    {
        $this->memory->failOn = 'connect';
        $this->lookup->outcome = LookupOutcome::verified(null);

        self::assertTrue($this->authorizer->authorize(self::HEADER));

        self::assertSame(['connect', 'close'], $this->memory->calls, 'no post-lookup command on a memory that failed before the lookup');
        self::assertSame('connect', $this->logger->withMessage('deep probe authorization: memory unavailable')[0]['context']['operation']);
    }

    public function testAMemoryFailingBeforeTheLookupRefusesAnUnavailableDatabaseWithoutConsulting(): void
    {
        $this->memory->failOn = 'token';
        $this->lookup->outcome = LookupOutcome::unavailable('PDOException');

        self::assertFalse($this->authorizer->authorize(self::HEADER));

        self::assertSame(['connect', 'token', 'close'], $this->memory->calls);
    }

    public function testANonKeyHeaderIsRefusedWithoutAnyIo(): void
    {
        foreach ([null, '', 'Bearer', 'Bearer not-checked-here.admin.jwt', 'Basic bGI6eA==', 'Bearer lb_short', 'Bearer '.self::KEY.' ', 'bearer '.self::KEY] as $header) {
            self::assertFalse($this->authorizer->authorize($header), var_export($header, true));
        }

        self::assertSame([], $this->memory->calls);
        self::assertSame(0, $this->lookup->lookups);
        self::assertSame([], $this->logger->records);
    }

    public function testTheKeyNeverReachesALogRecord(): void
    {
        $this->memory->failOn = 'connect';
        $this->lookup->outcome = LookupOutcome::unavailable('PDOException');

        $this->authorizer->authorize(self::HEADER);

        $this->logger->assertNothingContains(self::KEY);
        $this->logger->assertNothingContains(hash('sha256', self::KEY));
    }
}

final class FakeLookup implements KeyLookupInterface
{
    public LookupOutcome $outcome;
    public ?string $hash = null;
    public ?float $allowance = null;
    public int $lookups = 0;

    public function __construct()
    {
        $this->outcome = LookupOutcome::denied();
    }

    public function lookup(string $hash, float $allowanceSeconds): LookupOutcome
    {
        ++$this->lookups;
        $this->hash = $hash;
        $this->allowance = $allowanceSeconds;

        return $this->outcome;
    }
}

final class FakeMemory implements ProbeMemoryInterface
{
    /** @var list<string> */
    public array $calls = [];
    public string $token = '';
    public bool $consultAnswer = false;
    public ?string $failOn = null;
    public ?string $rememberedToken = null;
    public ?\DateTimeImmutable $rememberedExpiry = null;
    public ?\DateTimeImmutable $rememberedVerifiedAt = null;
    public ?\DateTimeImmutable $consultedAt = null;

    public function connect(): void
    {
        $this->record('connect');
    }

    public function token(string $hash): string
    {
        $this->record('token');

        return $this->token;
    }

    public function remember(string $hash, string $token, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $verifiedAt): bool
    {
        $this->record('remember');
        $this->rememberedToken = $token;
        $this->rememberedExpiry = $expiresAt;
        $this->rememberedVerifiedAt = $verifiedAt;

        return true;
    }

    public function deny(string $hash): void
    {
        $this->record('deny');
    }

    public function consult(string $hash, \DateTimeImmutable $now): bool
    {
        $this->record('consult');
        $this->consultedAt = $now;

        return $this->consultAnswer;
    }

    public function close(): void
    {
        $this->calls[] = 'close';
    }

    private function record(string $operation): void
    {
        $this->calls[] = $operation;
        if ($operation === $this->failOn) {
            throw new MemoryUnavailable($operation, new \RedisException('read error on connection'));
        }
    }
}
