<?php

declare(strict_types=1);

namespace App\Shared\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs bin/probe-key-lookup as a child process with a kill deadline (design
 * decision 3): the hash goes to the child's stdin, DATABASE_URL to its
 * environment, and when the allowance passes Process::checkTimeout() sends
 * SIGTERM then SIGKILL — the operating system reclaims a client stuck on a
 * connection that never completes, a stalled statement or a lost response,
 * cleanup included. Anything but a clean V/D/U line is "unavailable".
 */
final class KeyLookupProcess implements KeyLookupInterface
{
    public function __construct(
        #[Autowire(env: 'DATABASE_URL')]
        private readonly string $databaseUrl,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function lookup(#[\SensitiveParameter] string $hash, float $allowanceSeconds): LookupOutcome
    {
        $php = (new PhpExecutableFinder())->find(false);
        $process = new Process(
            [false === $php ? 'php' : $php, $this->projectDir.'/bin/probe-key-lookup', \sprintf('--statement-timeout-ms=%d', (int) round($allowanceSeconds * 1000))],
            $this->projectDir,
            ['DATABASE_URL' => $this->databaseUrl],
            $hash,
            $allowanceSeconds,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return LookupOutcome::unavailable('ProcessTimedOutException');
        } catch (RuntimeException $e) {
            return LookupOutcome::unavailable((new \ReflectionClass($e))->getShortName());
        }

        if (!$process->isSuccessful()) {
            return LookupOutcome::unavailable('NonZeroExit');
        }

        return self::interpret($process->getOutput());
    }

    /**
     * The child's one-line protocol: `V <RFC 3339|->`, `D`, `U <class>`;
     * anything else is treated as unavailable (fail-closed), never as verified.
     */
    public static function interpret(string $output): LookupOutcome
    {
        $line = trim($output);
        if ('' === $line || str_contains($line, "\n")) {
            return LookupOutcome::unavailable('UnexpectedOutput');
        }
        if ('D' === $line) {
            return LookupOutcome::denied();
        }
        if ('V -' === $line) {
            return LookupOutcome::verified(null);
        }
        if (str_starts_with($line, 'V ')) {
            $expiresAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, substr($line, 2));

            return false === $expiresAt ? LookupOutcome::unavailable('UnexpectedOutput') : LookupOutcome::verified($expiresAt);
        }
        if (1 === preg_match('/^U ([A-Za-z0-9_\\\\]{1,120})$/', $line, $m)) {
            return LookupOutcome::unavailable($m[1]);
        }

        return LookupOutcome::unavailable('UnexpectedOutput');
    }
}
