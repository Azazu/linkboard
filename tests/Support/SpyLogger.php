<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Psr\Log\AbstractLogger;

/**
 * Collects log records so a test asserts what was logged — and what was not
 * (the deep-probe authorizer must never log a key).
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function withMessage(string $message): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => $record['message'] === $message));
    }

    public function assertNothingContains(string $needle): void
    {
        Assert::assertNotSame([], $this->records, 'nothing was logged — the assertion would be vacuous');
        foreach ($this->records as $record) {
            Assert::assertStringNotContainsString($needle, $record['message']);
            Assert::assertStringNotContainsString($needle, json_encode($record['context'], \JSON_THROW_ON_ERROR));
        }
    }
}
