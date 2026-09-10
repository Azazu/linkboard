<?php

declare(strict_types=1);

namespace App\Tests\Integration\Redirect\Geo;

use App\Redirect\Geo\ChainCountryResolver;
use App\Redirect\Geo\CountryResolverInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec routing-rules "Unknown resolver name fails boot" (design decision 8):
 * App\Kernel::boot() runs the startup checks, which construct the chain. The
 * env var is read at runtime, so the cached test container is enough; the test
 * never retrieves or constructs the chain itself in the failing case.
 */
#[CoversNothing]
final class CountryResolversBootTest extends KernelTestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = $_SERVER['COUNTRY_RESOLVERS'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->previous) {
            unset($_SERVER['COUNTRY_RESOLVERS'], $_ENV['COUNTRY_RESOLVERS']);
        } else {
            $_SERVER['COUNTRY_RESOLVERS'] = $_ENV['COUNTRY_RESOLVERS'] = $this->previous;
        }
        parent::tearDown();
    }

    public function testAnUnknownResolverNameFailsTheBoot(): void
    {
        $_SERVER['COUNTRY_RESOLVERS'] = $_ENV['COUNTRY_RESOLVERS'] = 'bogus';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown country resolver "bogus" in COUNTRY_RESOLVERS');

        self::bootKernel();
    }

    public function testTheFixedResolverBootsAndIsTheChain(): void
    {
        $_SERVER['COUNTRY_RESOLVERS'] = $_ENV['COUNTRY_RESOLVERS'] = 'fixed';

        self::bootKernel();

        self::assertInstanceOf(ChainCountryResolver::class, self::getContainer()->get(CountryResolverInterface::class));
    }
}
