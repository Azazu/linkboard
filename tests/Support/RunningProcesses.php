<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * What `ps` would show: the command lines of every process on the host, read
 * from /proc. Used to prove that no lookup child outlives its deadline and
 * that the key hash never appears on a command line.
 */
final class RunningProcesses
{
    /** @return list<string> command lines, arguments joined by a space */
    public static function commandLines(): array
    {
        $lines = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if (\is_string($raw) && '' !== $raw) {
                $lines[] = str_replace("\0", ' ', rtrim($raw, "\0"));
            }
        }

        return $lines;
    }

    /** @return list<string> the command lines that run bin/probe-key-lookup */
    public static function lookupChildren(): array
    {
        return array_values(array_filter(self::commandLines(), static fn (string $line): bool => str_contains($line, 'bin/probe-key-lookup')));
    }
}
