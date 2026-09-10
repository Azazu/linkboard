<?php

declare(strict_types=1);

namespace App\Redirect;

use App\Click\Visit;
use App\Redirect\Detection\DeviceDetectionInterface;
use App\Redirect\Geo\CountryResolverInterface;
use App\Redirect\Language\AcceptLanguage;

/**
 * Detection + country + language for a well-formed Visit (design decision 5).
 * Called inside the redirect's guard: anything thrown here degrades the
 * request to the default target.
 */
final readonly class VisitorProfiler
{
    public function __construct(
        private DeviceDetectionInterface $detection,
        private CountryResolverInterface $countries,
    ) {
    }

    public function profile(Visit $visit): VisitorProfile
    {
        $client = $this->detection->detect($visit);

        return new VisitorProfile(
            $client->deviceType,
            $client->os,
            $client->browser,
            $client->isBot,
            $this->countries->resolve($visit),
            AcceptLanguage::primary($visit->acceptLanguage),
        );
    }
}
