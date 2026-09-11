<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Analytics\Dto\CountryRow;
use App\Analytics\Dto\DeviceTypeRow;
use App\Analytics\Dto\OsRow;
use App\Analytics\Dto\RefererRow;
use App\Analytics\Dto\TopLinkRow;
use App\Analytics\Dto\VariantRow;
use App\Analytics\Query\BreakdownQuery;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;

/** Spec analytics "Countries", "Devices", "Referrers", "Variants" reports and the admin top links. */
#[CoversClass(BreakdownQuery::class)]
final class BreakdownQueryTest extends AnalyticsQueryTestCase
{
    private const string FROM = '2026-09-01T00:00:00Z';
    private const string TO = '2026-09-08T00:00:00Z';

    public function testCountriesWithShareRankAndLimit(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'DE'], 5);
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'US'], 3);
        self::click($link, '2026-09-02T10:00:00Z', ['country' => null], 2);
        self::click($link, '2026-08-02T10:00:00Z', ['country' => 'FR'], 9); // outside the period

        $top2 = $this->query()->countries($this->request($link, self::FROM, self::TO, limit: 2));
        self::assertSame(10, $top2->total);
        self::assertEquals([new CountryRow('DE', 5, 50.0, 1), new CountryRow('US', 3, 30.0, 2)], $top2->items);

        $all = $this->query()->countries($this->request($link, self::FROM, self::TO));
        self::assertCount(3, $all->items);
        self::assertEquals(new CountryRow(null, 2, 20.0, 3), $all->items[2]);
    }

    public function testEqualCountsShareARank(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'DE'], 3);
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'US'], 3);
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'FR'], 1);

        $rows = $this->query()->countries($this->request($link, self::FROM, self::TO))->items;

        self::assertSame([['DE', 1], ['US', 1], ['FR', 3]], array_map(static fn (CountryRow $r): array => [$r->country, $r->rank], $rows));
        self::assertSame(42.9, $rows[0]->share);
    }

    public function testDevicesHasTwoBreakdowns(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', ['deviceType' => 'smartphone', 'os' => 'iOS'], 4);
        self::click($link, '2026-09-02T10:00:00Z', ['deviceType' => 'smartphone', 'os' => 'Android'], 2);
        self::click($link, '2026-09-02T10:00:00Z', ['deviceType' => 'desktop', 'os' => 'Windows'], 3);
        self::click($link, '2026-09-02T10:00:00Z', ['deviceType' => null, 'os' => null]);

        $devices = $this->query()->devices($this->request($link, self::FROM, self::TO));

        self::assertSame(10, $devices->total);
        self::assertEquals([new DeviceTypeRow('smartphone', 6, 60.0), new DeviceTypeRow('desktop', 3, 30.0), new DeviceTypeRow(null, 1, 10.0)], $devices->byDeviceType);
        self::assertEquals([new OsRow('iOS', 4, 40.0), new OsRow('Windows', 3, 30.0), new OsRow('Android', 2, 20.0), new OsRow(null, 1, 10.0)], $devices->byOs);
    }

    public function testReferrersWithDirectGroup(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', ['referer' => 'news.example.org'], 4);
        self::click($link, '2026-09-02T10:00:00Z', ['referer' => 't.co']);
        self::click($link, '2026-09-02T10:00:00Z', ['referer' => null], 5);

        $referrers = $this->query()->referrers($this->request($link, self::FROM, self::TO));

        self::assertSame(10, $referrers->total);
        self::assertEquals([new RefererRow('direct', 5, 50.0, 1), new RefererRow('news.example.org', 4, 40.0, 2), new RefererRow('t.co', 1, 10.0, 3)], $referrers->items);
    }

    public function testVariantsCountOnlyVariantResolvedClicks(): void
    {
        $link = $this->link();
        foreach (['v1', 'v2', 'v3', 'v4', 'v1', 'v2'] as $visitor) {
            self::click($link, '2026-09-02T10:00:00Z', ['variant' => 'A', 'visitor' => $visitor]);
        }
        foreach (['w1', 'w2', 'w3', 'w4'] as $visitor) {
            self::click($link, '2026-09-02T10:00:00Z', ['variant' => 'B', 'visitor' => $visitor]);
        }
        self::click($link, '2026-09-02T10:00:00Z', ['resolvedBy' => 'device'], 5);

        $variants = $this->query()->variants($this->request($link, self::FROM, self::TO));

        self::assertSame(10, $variants->total);
        self::assertEquals([new VariantRow('A', 6, 4, 60.0), new VariantRow('B', 4, 4, 40.0)], $variants->items);

        $none = $this->query()->variants($this->request($this->link(), self::FROM, self::TO));
        self::assertSame(0, $none->total);
        self::assertSame([], $none->items);
    }

    public function testBotsToggleOnABreakdown(): void
    {
        $link = $this->link();
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'DE'], 8);
        self::click($link, '2026-09-02T10:00:00Z', ['country' => 'DE', 'bot' => true], 2);

        self::assertSame(8, $this->query()->countries($this->request($link, self::FROM, self::TO))->total);
        self::assertSame(10, $this->query()->countries($this->request($link, self::FROM, self::TO, includeBots: true))->total);
    }

    public function testTopLinksCarrySlugAndOwner(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $a1 = LinkFactory::createOne(['owner' => $a, 'slug' => 'a-one']);
        $a2 = LinkFactory::new()->inactive()->create(['owner' => $a, 'slug' => 'a-two']);
        $b1 = LinkFactory::createOne(['owner' => $b, 'slug' => 'b-one']);
        self::click($a1, '2026-09-02T10:00:00Z', ['visitor' => 'x'], 5);
        self::click($a2, '2026-09-02T10:00:00Z', [], 3);
        self::click($b1, '2026-09-02T10:00:00Z', [], 4);
        self::click($b1, '2026-08-02T10:00:00Z', [], 40);

        $top = $this->query()->topLinks($this->request(null, self::FROM, self::TO, limit: 2));

        self::assertSame(12, $top->total);
        self::assertEquals([
            new TopLinkRow((string) $a1->getId(), 'a-one', (string) $a->getId(), 5, 1, 1),
            new TopLinkRow((string) $b1->getId(), 'b-one', (string) $b->getId(), 4, 4, 2),
        ], $top->items);
    }

    private function query(): BreakdownQuery
    {
        return new BreakdownQuery(self::connection());
    }
}
