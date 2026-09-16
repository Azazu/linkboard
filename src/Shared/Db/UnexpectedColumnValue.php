<?php

declare(strict_types=1);

namespace App\Shared\Db;

/**
 * A column of a result row did not hold a value the reader could convert.
 *
 * Raised rather than cast, because a cast is what hid the problem: `(int) null`
 * is `0`, and a report showing zero clicks for a column a query stopped
 * returning is worse than an error (change harden-gate-floor, design decision
 * 2). The message names the column and the type received — never the value,
 * which may be data.
 */
final class UnexpectedColumnValue extends \UnexpectedValueException
{
    public function __construct(
        public readonly string $column,
        string $expected,
        string $received,
    ) {
        parent::__construct(\sprintf('Column "%s": expected %s, got %s.', $column, $expected, $received));
    }
}
