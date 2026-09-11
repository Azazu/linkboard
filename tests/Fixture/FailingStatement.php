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
 * Test-only DBAL middleware (when@test, tag doctrine.middleware): throws on
 * the first statement whose SQL contains the armed needle — failure injection
 * at a chosen point of a multi-statement transaction (spec demo-data "A
 * failure during seeding SHALL leave no partial data"). Disarmed after it
 * fired, and by reset().
 */
final class FailingStatement implements Middleware
{
    private static ?string $needle = null;

    public static function failOn(string $needle): void
    {
        self::$needle = $needle;
    }

    public static function reset(): void
    {
        self::$needle = null;
    }

    public static function check(string $sql): void
    {
        if (null !== self::$needle && str_contains($sql, self::$needle)) {
            self::$needle = null;

            throw new \RuntimeException('Injected failure on: '.$sql);
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
                        FailingStatement::check($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        FailingStatement::check($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        FailingStatement::check($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
