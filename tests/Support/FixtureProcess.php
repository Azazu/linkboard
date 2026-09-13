<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Starts a tests/Fixture/*.php network fixture and reads the port it prints
 * on its first stdout line. Stop it in `finally` with stop().
 */
final class FixtureProcess
{
    private function __construct(
        private readonly Process $process,
        public readonly int $port,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public static function start(string $script, array $arguments = []): self
    {
        $root = \dirname(__DIR__, 2);
        $process = new Process(['php', $root.'/tests/Fixture/'.$script, ...$arguments], $root, null, null, 60);
        $process->start();
        $process->waitUntil(static fn (string $type, string $output): bool => Process::OUT === $type && str_contains($output, "\n"));
        if (!$process->isRunning()) {
            throw new \RuntimeException(\sprintf('%s exited early: %s', $script, $process->getErrorOutput()));
        }
        $port = (int) trim(strtok($process->getOutput(), "\n") ?: '0');
        if ($port <= 0) {
            $process->stop(0);
            throw new \RuntimeException(\sprintf('%s printed no port', $script));
        }

        return new self($process, $port);
    }

    public function stop(): void
    {
        $this->process->stop(0);
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }
}
