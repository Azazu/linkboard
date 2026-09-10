<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect\Geo;

use App\Click\Visit;
use App\Redirect\Geo\HeaderCountryResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderCountryResolver::class)]
final class HeaderCountryResolverTest extends TestCase
{
    public function testTwoLettersUpperCased(): void
    {
        self::assertSame('DE', (new HeaderCountryResolver())->resolve($this->visit('de')));
        self::assertSame('US', (new HeaderCountryResolver())->resolve($this->visit('US')));
    }

    public function testUnknownMarkersAndMalformedValuesAreUnknown(): void
    {
        foreach (['XX', 'T1', 'D3', 'DEU', '', null] as $value) {
            self::assertNull((new HeaderCountryResolver())->resolve($this->visit($value)), var_export($value, true));
        }
    }

    private function visit(?string $proxyCountry): Visit
    {
        return new Visit('203.0.113.7', 'Probe/1.0', null, new \DateTimeImmutable(), proxyCountry: $proxyCountry);
    }
}
