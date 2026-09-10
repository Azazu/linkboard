<?php

declare(strict_types=1);

namespace App\Redirect\Geo;

use App\Click\Visit;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolver `geolite2`: the MaxMind GeoLite2 country database at
 * GEOIP_DATABASE_PATH, opened lazily once per process. Exception boundary
 * (design decision 8): opening the database is configuration — a missing or
 * unreadable file disables the resolver for the process with one warning;
 * an address that is not in the database is an unknown country with no log;
 * any other lookup error propagates to the redirect guard, which degrades the
 * request to the default target with one notice (FR-RUL-7). The IP never
 * appears in a log record.
 */
#[AutoconfigureTag('app.country_resolver', ['key' => 'geolite2'])]
final class GeoLite2CountryResolver implements CountryResolverInterface
{
    /** @var \Closure(string): (\Closure(string): ?string) database path → lookup(ip) → ISO code */
    private \Closure $readerFactory;
    /** @var (\Closure(string): ?string)|null */
    private ?\Closure $lookup = null;
    private bool $disabled = false;

    /**
     * @param (\Closure(string): (\Closure(string): ?string))|null $readerFactory test seam; production opens a GeoIp2 Reader
     */
    public function __construct(
        #[Autowire(env: 'resolve:GEOIP_DATABASE_PATH')]
        private readonly string $databasePath,
        private readonly LoggerInterface $logger,
        ?\Closure $readerFactory = null,
    ) {
        $this->readerFactory = $readerFactory ?? static function (string $path): \Closure {
            $reader = new Reader($path);

            return static fn (string $ip): ?string => $reader->country($ip)->country->isoCode;
        };
    }

    public function resolve(Visit $visit): ?string
    {
        if ($this->disabled) {
            return null;
        }
        $lookup = $this->lookup;
        if (null === $lookup) {
            try {
                $lookup = $this->lookup = ($this->readerFactory)($this->databasePath);
            } catch (\Throwable $e) {
                $this->disabled = true;
                $this->logger->warning('GeoLite2 database unavailable; the geolite2 country resolver is disabled for this process', ['exception' => $e::class, 'path_configured' => '' !== $this->databasePath]);

                return null;
            }
        }
        if (false === filter_var($visit->clientIp, \FILTER_VALIDATE_IP)) {
            return null;
        }
        try {
            $code = $lookup($visit->clientIp);
        } catch (AddressNotFoundException) {
            return null; // private, reserved or unlisted address: an unknown country, not a failure
        }

        return null === $code || '' === $code ? null : strtoupper($code);
    }
}
