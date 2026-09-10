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
 * Test-only DBAL middleware (registered under when@test, tag doctrine.middleware,
 * design decision 8): records every SQL string the application prepares or
 * executes so a test can assert the statement kinds of one request — the
 * redirect must execute one SELECT and no INSERT/UPDATE/DELETE.
 */
final class StatementRecorder implements Middleware
{
    /** @var list<string> */
    private static array $statements = [];

    public static function reset(): void
    {
        self::$statements = [];
    }

    /** @return list<string> */
    public static function statements(): array
    {
        return self::$statements;
    }

    public static function record(string $sql): void
    {
        self::$statements[] = $sql;
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        StatementRecorder::record($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        StatementRecorder::record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        StatementRecorder::record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
