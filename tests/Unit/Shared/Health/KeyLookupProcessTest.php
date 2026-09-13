<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Health;

use App\Shared\Health\KeyLookupProcess;
use App\Shared\Health\LookupOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The child's one-line protocol as the parent reads it (design decision 3):
 * only an exact V/D/U line is trusted; everything else is "unavailable" —
 * never "verified".
 */
#[CoversClass(KeyLookupProcess::class)]
#[CoversClass(LookupOutcome::class)]
final class KeyLookupProcessTest extends TestCase
{
    public function testAVerifiedLineCarriesTheExpiry(): void
    {
        $outcome = KeyLookupProcess::interpret("V 2030-01-01T00:00:00+00:00\n");

        self::assertTrue($outcome->isVerified());
        self::assertSame('2030-01-01T00:00:00+00:00', $outcome->expiresAt?->format(\DATE_ATOM));
    }

    public function testAVerifiedLineWithoutExpiryNeverExpires(): void
    {
        $outcome = KeyLookupProcess::interpret("V -\n");

        self::assertTrue($outcome->isVerified());
        self::assertNull($outcome->expiresAt);
    }

    public function testADeniedLine(): void
    {
        $outcome = KeyLookupProcess::interpret("D\n");

        self::assertTrue($outcome->isDenied());
        self::assertFalse($outcome->isVerified());
    }

    public function testAnUnavailableLineNamesTheReason(): void
    {
        $outcome = KeyLookupProcess::interpret("U PDOException\n");

        self::assertTrue($outcome->isUnavailable());
        self::assertSame('PDOException', $outcome->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n"];
        yield 'two lines' => ["V -\nD\n"];
        yield 'verified with a malformed date' => ["V tomorrow\n"];
        yield 'verified without a separator' => ["V\n"];
        yield 'unknown letter' => ["X\n"];
        yield 'unavailable with an odd reason' => ["U <script>\n"];
        yield 'a PHP warning' => ["Warning: something\nD\n"];
    }

    #[DataProvider('garbage')]
    public function testAnythingElseIsUnavailableNotVerified(string $output): void
    {
        $outcome = KeyLookupProcess::interpret($output);

        self::assertTrue($outcome->isUnavailable(), $output);
        self::assertSame('UnexpectedOutput', $outcome->reason);
    }
}
