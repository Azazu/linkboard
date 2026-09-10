<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Click\Visit;
use App\Redirect\Geo\CountryResolverInterface;

/**
 * Resolver `fixed` of the test environment (COUNTRY_RESOLVERS=fixed): a
 * constant IP → country map. The default test peer 203.0.113.7 stays unmapped
 * so the click tests of the previous change keep a null country.
 */
final class FixedMapCountryResolver implements CountryResolverInterface
{
    /** @var array<string, string> */
    public const array MAP = ['198.51.100.7' => 'DE', '198.51.100.44' => 'US', '198.51.100.33' => 'FR'];

    public function resolve(Visit $visit): ?string
    {
        return self::MAP[$visit->clientIp] ?? null;
    }
}
