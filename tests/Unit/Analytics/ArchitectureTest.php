<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Spec analytics "Reports are computed by the database from the click
 * records" / NFR-QA-2: the read model shares nothing with the write model but
 * the table — no click or link entity, no ORM, under src/Analytics.
 */
#[CoversNothing]
final class ArchitectureTest extends TestCase
{
    private const array FORBIDDEN = ['App\\Click\\Entity', 'App\\Link\\Entity', 'Doctrine\\ORM'];

    public function testAnalyticsReferencesNoEntityAndNoOrm(): void
    {
        $root = \dirname(__DIR__, 3).'/src/Analytics';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offences = [];
        $count = 0;
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            ++$count;
            $source = (string) file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($source, $needle)) {
                    $offences[] = substr($file->getPathname(), \strlen($root) + 1).' references '.$needle;
                }
            }
        }

        self::assertGreaterThan(0, $count, 'src/Analytics has PHP files');
        self::assertSame([], $offences);
    }
}
