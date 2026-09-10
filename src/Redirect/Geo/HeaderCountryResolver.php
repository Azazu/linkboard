<?php

declare(strict_types=1);

namespace App\Redirect\Geo;

use App\Click\Visit;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Resolver `header`: the country a trusted proxy put into the header named by
 * GEOIP_COUNTRY_HEADER (default CF-IPCountry). Trust is decided by
 * VisitFactory — Visit::$proxyCountry is null unless the request came through
 * TRUSTED_PROXIES. Cloudflare's `XX` (unknown) and `T1` (Tor) are unknown.
 */
#[AutoconfigureTag('app.country_resolver', ['key' => 'header'])]
final class HeaderCountryResolver implements CountryResolverInterface
{
    public function resolve(Visit $visit): ?string
    {
        $value = $visit->proxyCountry;
        if (null === $value || 1 !== preg_match('/^[A-Za-z]{2}$/D', $value)) {
            return null;
        }
        $country = strtoupper($value);

        return \in_array($country, ['XX', 'T1'], true) ? null : $country;
    }
}
