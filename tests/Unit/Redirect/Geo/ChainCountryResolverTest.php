<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect\Geo;

use App\Click\Visit;
use App\Redirect\Geo\ChainCountryResolver;
use App\Redirect\Geo\CountryResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainCountryResolver::class)]
final class ChainCountryResolverTest extends TestCase
{
    public function testFirstAnswerWinsInConfiguredOrder(): void
    {
        $chain = new ChainCountryResolver(new \ArrayIterator(['header' => $this->answering(null), 'geolite2' => $this->answering('FR'), 'fixed' => $this->answering('DE')]), 'header, geolite2');

        self::assertSame('FR', $chain->resolve($this->visit()));
        self::assertSame('DE', (new ChainCountryResolver(new \ArrayIterator(['header' => $this->answering(null), 'fixed' => $this->answering('DE')]), 'fixed,header'))->resolve($this->visit()));
        self::assertNull((new ChainCountryResolver(new \ArrayIterator(['header' => $this->answering(null)]), 'header'))->resolve($this->visit()));
    }

    public function testUnknownNameFailsConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown country resolver "bogus" in COUNTRY_RESOLVERS; known: header, geolite2.');

        new ChainCountryResolver(new \ArrayIterator(['header' => $this->answering(null), 'geolite2' => $this->answering(null)]), 'header,bogus');
    }

    public function testEmptyConfigurationFailsConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChainCountryResolver(new \ArrayIterator(['header' => $this->answering(null)]), ' , ');
    }

    public function testCheckIsANoOpOnceConstructed(): void
    {
        $chain = new ChainCountryResolver(new \ArrayIterator(['header' => $this->answering(null)]), 'header');
        $chain->check();
        self::assertNull($chain->resolve($this->visit()));
    }

    private function answering(?string $country): CountryResolverInterface
    {
        $resolver = $this->createStub(CountryResolverInterface::class);
        $resolver->method('resolve')->willReturn($country);

        return $resolver;
    }

    private function visit(): Visit
    {
        return new Visit('203.0.113.7', 'Probe/1.0', null, new \DateTimeImmutable());
    }
}
