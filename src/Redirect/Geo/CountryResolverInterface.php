<?php

declare(strict_types=1);

namespace App\Redirect\Geo;

use App\Click\Visit;

/**
 * FR-RUL-6: the visitor's country as an upper-case ISO 3166-1 alpha-2 code, or
 * null when unknown. Implementations are tagged `app.country_resolver` with a
 * `key` and selected in order by COUNTRY_RESOLVERS (ChainCountryResolver).
 */
interface CountryResolverInterface
{
    public function resolve(Visit $visit): ?string;
}
