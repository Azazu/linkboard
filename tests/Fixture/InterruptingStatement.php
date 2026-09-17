<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Test-only DBAL middleware (when@test, tag doctrine.middleware): runs a
 * callback the first time a statement's SQL contains the armed needle, before
 * that statement executes.
 *
 * It exists to make a race deterministic without threads or sleeps. The click
 * handler decides a message is inside the retention boundary and then inserts,
 * both inside one transaction; this fixture stops the world exactly between
 * those two moments so another connection can try to run the real maintenance
 * command there (change stretch-partition-clicks, Gate 2 round 1, finding 2).
 * Disarmed after it fired, and by reset().
 */
final class InterruptingStatement implements Middleware
{
    private static ?string $needle = null;

    /** @var (callable(): void)|null */
    private static $callback;

    /**
     * @param callable(): void $callback
     */
    public static function interruptOn(string $needle, callable $callback): void
    {
        self::$needle = $needle;
        self::$callback = $callback;
    }

    public static function reset(): void
    {
        self::$needle = null;
        self::$callback = null;
    }

    public static function check(string $sql): void
    {
        if (null === self::$needle || !str_contains($sql, self::$needle)) {
            return;
        }

        $callback = self::$callback;
        self::reset();
        if (null !== $callback) {
            $callback();
        }
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        InterruptingStatement::check($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        InterruptingStatement::check($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        InterruptingStatement::check($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
