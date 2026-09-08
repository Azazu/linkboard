<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Health;

use App\Shared\Health\HealthReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthReport::class)]
final class HealthReportTest extends TestCase
{
    public function testAllChecksPassingIsHealthyWith200(): void
    {
        $report = new HealthReport(['database' => true, 'redis' => true]);

        self::assertTrue($report->isHealthy());
        self::assertSame(200, $report->httpStatus());
        self::assertSame(
            ['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']],
            $report->toArray(),
        );
    }

    public function testOneFailingCheckIsUnhealthyWith503(): void
    {
        $report = new HealthReport(['database' => true, 'redis' => false]);

        self::assertFalse($report->isHealthy());
        self::assertSame(503, $report->httpStatus());
        self::assertSame(
            ['status' => 'fail', 'checks' => ['database' => 'ok', 'redis' => 'fail']],
            $report->toArray(),
        );
    }

    public function testNoChecksIsHealthy(): void
    {
        $report = new HealthReport([]);

        self::assertTrue($report->isHealthy());
        self::assertSame(['status' => 'ok', 'checks' => []], $report->toArray());
    }
}
