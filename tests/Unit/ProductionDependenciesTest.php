<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Production code may only use packages a production install provides.
 *
 * The deep-probe authorization was written against `symfony/process` while it
 * sat in `require-dev`, pulled in by a linter: `composer install --no-dev`
 * would have left a production request raising a class-not-found error instead
 * of authorizing or refusing (Gate 2 round 1, finding 1). Reading the lock is
 * the only check that sees this — the test suite always has the dev packages.
 */
#[CoversNothing]
final class ProductionDependenciesTest extends TestCase
{
    public function testNoRuntimeFileUsesADevelopmentOnlyPackage(): void
    {
        $devOnly = self::developmentOnlyPrefixes();
        self::assertNotSame([], $devOnly, 'the lock must list development packages, or this test proves nothing');

        $offences = [];
        foreach (self::runtimeFiles() as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($devOnly as $prefix => $package) {
                if (str_contains($contents, $prefix)) {
                    $offences[] = \sprintf('%s uses %s (%s is a require-dev package)', self::relative($file), $prefix, $package);
                }
            }
        }

        self::assertSame([], $offences, implode("\n", $offences));
    }

    public function testTheLookupProcessComponentShipsInProduction(): void
    {
        // The one this test was written for, named so a regression reads plainly.
        self::assertArrayHasKey('symfony/process', self::lock('packages'), 'the key lookup runs it in production');
        self::assertArrayNotHasKey('symfony/process', self::lock('packages-dev'));
    }

    /**
     * @return array<string, string> namespace prefix => package, for prefixes no production package provides
     */
    private static function developmentOnlyPrefixes(): array
    {
        $production = [];
        foreach (self::lock('packages') as $package) {
            foreach (self::prefixes($package) as $prefix) {
                $production[$prefix] = true;
            }
        }

        $devOnly = [];
        foreach (self::lock('packages-dev') as $name => $package) {
            foreach (self::prefixes($package) as $prefix) {
                if (!isset($production[$prefix])) {
                    $devOnly[$prefix] = $name;
                }
            }
        }

        return $devOnly;
    }

    /**
     * @param array<string, mixed> $package
     *
     * @return list<string>
     */
    private static function prefixes(array $package): array
    {
        $autoload = $package['autoload'] ?? [];
        $prefixes = [];
        foreach (['psr-4', 'psr-0'] as $standard) {
            $map = \is_array($autoload) ? ($autoload[$standard] ?? []) : [];
            foreach (\is_array($map) ? array_keys($map) : [] as $prefix) {
                if (\is_string($prefix) && '' !== $prefix) {
                    $prefixes[] = $prefix;
                }
            }
        }

        return $prefixes;
    }

    /**
     * @return array<string, array<string, mixed>> package name => package
     */
    private static function lock(string $section): array
    {
        static $lock = null;
        if (null === $lock) {
            $lock = json_decode((string) file_get_contents(self::root().'/composer.lock'), true, 512, \JSON_THROW_ON_ERROR);
        }
        \assert(\is_array($lock));
        $packages = [];
        foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
            \assert(\is_array($package) && \is_string($package['name']));
            $packages[$package['name']] = $package;
        }

        return $packages;
    }

    /** @return list<string> every file a production request can execute */
    private static function runtimeFiles(): array
    {
        $files = [self::root().'/bin/probe-key-lookup', self::root().'/public/index.php'];
        $directory = new \RecursiveDirectoryIterator(self::root().'/src', \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private static function relative(string $path): string
    {
        return str_replace(self::root().'/', '', $path);
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
