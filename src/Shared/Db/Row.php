<?php

declare(strict_types=1);

namespace App\Shared\Db;

/**
 * Reads one column of a DBAL result row as the type the caller needs.
 *
 * The analytics read model works on `fetchAllAssociative()` rows, which are
 * `array<string, mixed>`: PDO returns `bigint`, `numeric` and the window
 * functions' results as strings, plain `integer` columns as ints. Casting that
 * `mixed` is what PHPStan level 9 objects to, and the objection is a real one —
 * `(int) $row['clicks']` on a column the query no longer selects is `0`, a
 * number a report will happily publish.
 *
 * So each reader accepts exactly the shapes PostgreSQL does produce for its
 * type and throws `UnexpectedColumnValue` for everything else, including a
 * missing column.
 *
 * What this does NOT check: the query. A column that returns the wrong number
 * in the right type passes. This converts a value at the moment it arrives; it
 * is not a schema contract.
 */
final class Row
{
    private function __construct()
    {
    }

    /**
     * An integer column: `int` from a plain integer column, or the decimal
     * string PDO returns for `bigint` and for `count(*)`.
     *
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = self::present($row, $column, 'an integer');

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw new UnexpectedColumnValue($column, 'an integer', get_debug_type($value));
    }

    /**
     * A numeric column: `float`, `int`, or the decimal string PDO returns for
     * `numeric` — which is what `round()` and the share expressions produce.
     *
     * @param array<string, mixed> $row
     */
    public static function float(array $row, string $column): float
    {
        $value = self::present($row, $column, 'a number');

        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new UnexpectedColumnValue($column, 'a number', get_debug_type($value));
    }

    /**
     * A nullable numeric column — the summary's delta is `NULL` when the
     * previous period had no clicks, which is a different statement from zero.
     *
     * @param array<string, mixed> $row
     */
    public static function nullableFloat(array $row, string $column): ?float
    {
        $value = self::present($row, $column, 'a number or null');

        if (null === $value) {
            return null;
        }

        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new UnexpectedColumnValue($column, 'a number or null', get_debug_type($value));
    }

    /**
     * A text column. Deliberately strict: an `int` is not silently formatted,
     * because a caller that wants a number's text wants to say so.
     *
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = self::present($row, $column, 'a string');

        if (\is_string($value)) {
            return $value;
        }

        throw new UnexpectedColumnValue($column, 'a string', get_debug_type($value));
    }

    /**
     * A nullable text column — `country`, `device_type` and `os` are null for a
     * click whose resolver could not tell.
     *
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $column): ?string
    {
        $value = self::present($row, $column, 'a string or null');

        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw new UnexpectedColumnValue($column, 'a string or null', get_debug_type($value));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function present(array $row, string $column, string $expected): mixed
    {
        if (!\array_key_exists($column, $row)) {
            throw new UnexpectedColumnValue($column, $expected, 'no such column in the row');
        }

        return $row[$column];
    }
}
