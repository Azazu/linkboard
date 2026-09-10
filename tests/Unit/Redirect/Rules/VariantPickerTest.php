<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect\Rules;

use App\Link\Rules\Variant;
use App\Redirect\Rules\VariantPicker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Spec routing-rules "Deterministic A/B assignment" (design decision 10). */
#[CoversClass(VariantPicker::class)]
final class VariantPickerTest extends TestCase
{
    public function testSameInputsSameVariant(): void
    {
        $variants = self::variants(50, 50);
        $link = Uuid::v7();
        $first = VariantPicker::pick($variants, $link, '203.0.113.7', 'Probe/1.0');
        for ($i = 0; $i < 100; ++$i) {
            self::assertSame($first, VariantPicker::pick($variants, $link, '203.0.113.7', 'Probe/1.0'));
        }
        self::assertSame(VariantPicker::bucket($link, '', 'Probe/1.0'), VariantPicker::bucket($link, '', 'Probe/1.0'), 'an unknown IP is still deterministic');
    }

    public function testDistributionFollowsTheWeights(): void
    {
        $variants = self::variants(70, 30);
        $link = Uuid::v7();
        $counts = ['A' => 0, 'B' => 0];
        for ($i = 0; $i < 10_000; ++$i) {
            ++$counts[VariantPicker::pick($variants, $link, '10.'.($i >> 16 & 255).'.'.($i >> 8 & 255).'.'.($i & 255), 'UA/'.$i)->name];
        }

        self::assertEqualsWithDelta(70.0, $counts['A'] / 100, 3.0);
        self::assertEqualsWithDelta(30.0, $counts['B'] / 100, 3.0);
    }

    public function testCumulativeBoundaries(): void
    {
        $variants = self::variants(50, 50);
        self::assertSame('A', VariantPicker::forBucket($variants, 0)->name);
        self::assertSame('A', VariantPicker::forBucket($variants, 49)->name);
        self::assertSame('B', VariantPicker::forBucket($variants, 50)->name);
        self::assertSame('B', VariantPicker::forBucket($variants, 99)->name);
        $three = [new Variant('A', 10, 'https://example.com/a'), new Variant('B', 20, 'https://example.com/b'), new Variant('C', 70, 'https://example.com/c')];
        self::assertSame(['A', 'B', 'B', 'C', 'C'], array_map(static fn (int $b): string => VariantPicker::forBucket($three, $b)->name, [9, 10, 29, 30, 99]));
    }

    public function testBucketsAreInRange(): void
    {
        for ($i = 0; $i < 1_000; ++$i) {
            $bucket = VariantPicker::bucket(Uuid::v7(), '203.0.113.'.($i % 256), 'UA/'.$i);
            self::assertGreaterThanOrEqual(0, $bucket);
            self::assertLessThan(VariantPicker::BUCKETS, $bucket);
        }
    }

    /**
     * The link id is part of the hash input: fixed ids with known, distinct
     * buckets prove it (crc32 over "<uuid>\0<ip>\0<ua>" computed once and pinned);
     * two other fixed ids share bucket 1 — a legitimate collision, so a test
     * must never require two ids to differ (Gate 2 finding 1).
     */
    public function testTheLinkIdContributesToTheBucketAndCollisionsAreLegitimate(): void
    {
        $ip = '203.0.113.7';
        $ua = 'Probe/1.0';
        $one = Uuid::fromString('0192b6f0-0000-7000-8000-000000000001');
        $two = Uuid::fromString('0192b6f0-0000-7000-8000-000000000002');
        $three = Uuid::fromString('0192b6f0-0000-7000-8000-000000000003');

        self::assertSame(31, VariantPicker::bucket($one, $ip, $ua));
        self::assertSame(34, VariantPicker::bucket($two, $ip, $ua));
        self::assertSame(74, VariantPicker::bucket($three, $ip, $ua));
        self::assertSame(16, VariantPicker::bucket($one, '', $ua), 'an unknown IP is still deterministic');

        $variants = self::variants(50, 50);
        foreach ([$one, $two, $three] as $link) {
            self::assertSame(VariantPicker::pick($variants, $link, $ip, $ua), VariantPicker::pick($variants, $link, $ip, $ua), 'self-consistent per link');
        }
        self::assertSame('A', VariantPicker::pick($variants, $one, $ip, $ua)->name);
        self::assertSame('B', VariantPicker::pick($variants, $three, $ip, $ua)->name);

        $seven = Uuid::fromString('0192b6f0-0000-7000-8000-000000000007');
        $eight = Uuid::fromString('0192b6f0-0000-7000-8000-000000000008');
        self::assertSame(1, VariantPicker::bucket($seven, $ip, $ua));
        self::assertSame(1, VariantPicker::bucket($eight, $ip, $ua), 'distinct links may legitimately share a bucket');
    }

    /**
     * @return non-empty-list<Variant>
     */
    private static function variants(int $a, int $b): array
    {
        return [new Variant('A', $a, 'https://example.com/a'), new Variant('B', $b, 'https://example.com/b')];
    }
}
