<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Db;

use App\Shared\Db\Row;
use App\Shared\Db\UnexpectedColumnValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reader accepts what PostgreSQL produces and refuses everything else.
 *
 * The refusals are the point: `(int) null` is `0`, and the report that
 * publishes that zero is the failure this class exists to turn into an
 * exception (change harden-gate-floor, design decision 2).
 */
#[CoversClass(Row::class)]
#[CoversClass(UnexpectedColumnValue::class)]
final class RowTest extends TestCase
{
    public function testIntTakesAPlainIntegerAndTheStringPdoReturnsForBigint(): void
    {
        self::assertSame(42, Row::int(['clicks' => 42], 'clicks'));
        self::assertSame(1020279, Row::int(['clicks' => '1020279'], 'clicks'), 'count(*) arrives as a decimal string');
        self::assertSame(-7, Row::int(['delta' => '-7'], 'delta'));
        self::assertSame(0, Row::int(['clicks' => '0'], 'clicks'));
    }

    public function testFloatTakesFloatIntAndTheStringPdoReturnsForNumeric(): void
    {
        self::assertSame(12.5, Row::float(['share' => 12.5], 'share'));
        self::assertSame(3.0, Row::float(['share' => 3], 'share'));
        self::assertSame(0.1, Row::float(['share' => '0.1'], 'share'), 'round(…, 1) arrives as a decimal string');
        self::assertSame(100.0, Row::float(['share' => '100.0'], 'share'));
    }

    public function testStringTakesAStringAndNullableStringAlsoTakesNull(): void
    {
        self::assertSame('app-download', Row::string(['slug' => 'app-download'], 'slug'));
        self::assertSame('', Row::string(['slug' => ''], 'slug'), 'empty is a value, not an absence');
        self::assertSame('DE', Row::nullableString(['country' => 'DE'], 'country'));
        self::assertNull(Row::nullableString(['country' => null], 'country'), 'a click whose resolver could not tell');
        self::assertSame(-12.5, Row::nullableFloat(['delta' => '-12.5'], 'delta'));
        self::assertNull(Row::nullableFloat(['delta' => null], 'delta'), 'no previous clicks is not a delta of zero');
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>, string): mixed, mixed, string}>
     */
    public static function refused(): iterable
    {
        $int = static fn (array $row, string $column): int => Row::int($row, $column);
        $float = static fn (array $row, string $column): float => Row::float($row, $column);
        $string = static fn (array $row, string $column): string => Row::string($row, $column);
        $nullable = static fn (array $row, string $column): ?string => Row::nullableString($row, $column);
        $nullableFloat = static fn (array $row, string $column): ?float => Row::nullableFloat($row, $column);

        yield 'int of null — the cast that would have returned 0' => [$int, null, 'null'];
        yield 'int of a float' => [$int, 1.5, 'float'];
        yield 'int of a non-numeric string' => [$int, 'many', 'string'];
        yield 'int of a decimal string' => [$int, '1.5', 'string'];
        yield 'int of a bool' => [$int, true, 'bool'];
        yield 'int of an array' => [$int, [1], 'array'];
        yield 'float of null' => [$float, null, 'null'];
        yield 'float of a non-numeric string' => [$float, 'NaN-ish', 'string'];
        yield 'float of a bool' => [$float, false, 'bool'];
        yield 'string of null' => [$string, null, 'null'];
        yield 'string of an int — no silent formatting' => [$string, 7, 'int'];
        yield 'string of an array' => [$string, ['a'], 'array'];
        yield 'nullable string of an int' => [$nullable, 7, 'int'];
        yield 'nullable string of an array' => [$nullable, [], 'array'];
        yield 'nullable float of a non-numeric string' => [$nullableFloat, 'none', 'string'];
        yield 'nullable float of a bool' => [$nullableFloat, true, 'bool'];
    }

    /**
     * @param callable(array<string, mixed>, string): mixed $read
     */
    #[DataProvider('refused')]
    public function testRefusesWhatItCannotConvertAndNamesTheColumn(callable $read, mixed $value, string $type): void
    {
        $this->expectException(UnexpectedColumnValue::class);
        $this->expectExceptionMessage('Column "clicks"');
        $this->expectExceptionMessage($type);

        $read(['clicks' => $value], 'clicks');
    }

    public function testAMissingColumnIsRefusedByEveryReaderAndSaysSo(): void
    {
        foreach ([
            'int' => static fn (): mixed => Row::int([], 'total'),
            'float' => static fn (): mixed => Row::float([], 'total'),
            'string' => static fn (): mixed => Row::string([], 'total'),
            'nullableString' => static fn (): mixed => Row::nullableString([], 'total'),
            'nullableFloat' => static fn (): mixed => Row::nullableFloat([], 'total'),
        ] as $reader => $read) {
            try {
                $read();
                self::fail("Row::$reader() accepted a row without the column");
            } catch (UnexpectedColumnValue $e) {
                self::assertSame('total', $e->column);
                self::assertStringContainsString('no such column in the row', $e->getMessage());
            }
        }
    }

    public function testAColumnPresentButNullIsNotTheSameAsAMissingColumn(): void
    {
        // the distinction matters when reading a report: a null share is a
        // query that changed shape, a missing one is a query that changed name
        try {
            Row::float(['share' => null], 'share');
            self::fail('a null share was accepted');
        } catch (UnexpectedColumnValue $e) {
            self::assertStringContainsString('got null', $e->getMessage());
            self::assertStringNotContainsString('no such column', $e->getMessage());
        }
    }
}
