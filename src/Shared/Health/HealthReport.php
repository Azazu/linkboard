<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * Result of a deep probe: one boolean per dependency, plus the derived
 * overall status and HTTP code the endpoint must answer with.
 */
final readonly class HealthReport
{
    /**
     * @param array<string, bool> $checks dependency name => reachable
     */
    public function __construct(
        public array $checks,
    ) {
    }

    public function isHealthy(): bool
    {
        foreach ($this->checks as $ok) {
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    public function httpStatus(): int
    {
        return $this->isHealthy() ? 200 : 503;
    }

    /**
     * @return array{status: string, checks: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->isHealthy() ? 'ok' : 'fail',
            'checks' => array_map(static fn (bool $ok): string => $ok ? 'ok' : 'fail', $this->checks),
        ];
    }
}
