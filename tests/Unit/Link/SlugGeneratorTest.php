<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link;

use App\Link\ReservedSlugs;
use App\Link\SlugGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlugGenerator::class)]
#[CoversClass(ReservedSlugs::class)]
final class SlugGeneratorTest extends TestCase
{
    public function testGeneratesSevenBase62CharactersAndVaries(): void
    {
        $generator = new SlugGenerator();
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $slug = $generator->generate();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9]{7}$/', $slug);
            self::assertFalse(ReservedSlugs::contains($slug));
            $seen[$slug] = true;
        }
        self::assertGreaterThan(190, \count($seen), 'a secure source does not repeat itself 200 times in a row');
    }

    public function testReservedListContainsTheSpecifiedWords(): void
    {
        foreach (['api', 'admin', 'login', 'logout', 'register', 'dashboard', 'links', 'api-keys', 'health', 'docs', 'qr', 'assets', 'build', 'bundles', '_profiler', '_wdt', '_error'] as $word) {
            self::assertTrue(ReservedSlugs::contains($word), $word);
        }
        self::assertFalse(ReservedSlugs::contains('Admin'), 'reserved words are exact: case-sensitive slugs');
    }
}
