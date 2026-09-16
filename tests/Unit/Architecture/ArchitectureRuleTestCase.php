<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The rules of NFR-QA-2, enforced "where cheap": each reads the source files
 * it governs and reports every offence with its path.
 *
 * What a text scan cannot see is stated by each rule, because a passing suite
 * must not be read as a proof it is not: a class named through a variable, a
 * query built inside a collaborator the governed file merely calls, or a rule
 * evaded by string concatenation all pass. The rules catch the shape code
 * actually drifts into — an import and a call — and each is demonstrated
 * failing on exactly that shape (change harden-quality-and-docs, design
 * decision 2).
 *
 * `deptrac` is deliberately absent: NFR-QA-2 admits it only once a violation
 * has actually occurred twice, and none has occurred once.
 */
abstract class ArchitectureRuleTestCase extends TestCase
{
    protected const string SRC = __DIR__.'/../../../src';

    /**
     * @return list<string> absolute paths, non-empty — a rule that scanned nothing proves nothing
     */
    protected static function filesUnder(string $directory, ?callable $keep = null): array
    {
        $root = realpath($directory);
        self::assertIsString($root, "$directory exists");

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $path = $file->getPathname();
            if (null === $keep || $keep($path)) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    protected static function relative(string $path): string
    {
        $root = realpath(__DIR__.'/../../..');

        return \is_string($root) ? str_replace($root.'/', '', $path) : $path;
    }
}
