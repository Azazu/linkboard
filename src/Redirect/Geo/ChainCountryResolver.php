<?php

declare(strict_types=1);

namespace App\Redirect\Geo;

use App\Click\Visit;
use App\Shared\Boot\StartupCheckInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The configured chain (design decision 8): COUNTRY_RESOLVERS names tagged
 * resolvers by key, in order; the first non-null answer wins. The constructor
 * validates the names, and the class is a startup check so that App\Kernel
 * constructs it on every boot — an unknown name fails the application before
 * a request, command or message is handled.
 */
#[AutoconfigureTag('app.startup_check')]
final class ChainCountryResolver implements CountryResolverInterface, StartupCheckInterface
{
    /** @var list<CountryResolverInterface> */
    private array $chain = [];

    /**
     * @param iterable<string, CountryResolverInterface> $resolvers tagged `app.country_resolver`, indexed by their `key`
     */
    public function __construct(
        #[AutowireIterator('app.country_resolver', indexAttribute: 'key')]
        iterable $resolvers,
        #[Autowire(env: 'COUNTRY_RESOLVERS')]
        string $configured,
    ) {
        $byKey = [];
        foreach ($resolvers as $key => $resolver) {
            $byKey[(string) $key] = $resolver;
        }
        $names = array_values(array_filter(array_map(trim(...), explode(',', $configured)), static fn (string $n): bool => '' !== $n));
        if ([] === $names) {
            throw new \InvalidArgumentException(\sprintf('COUNTRY_RESOLVERS must name at least one country resolver; known: %s.', implode(', ', array_keys($byKey))));
        }
        foreach ($names as $name) {
            $this->chain[] = $byKey[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown country resolver "%s" in COUNTRY_RESOLVERS; known: %s.', $name, implode(', ', array_keys($byKey))));
        }
    }

    public function resolve(Visit $visit): ?string
    {
        foreach ($this->chain as $resolver) {
            $country = $resolver->resolve($visit);
            if (null !== $country) {
                return $country;
            }
        }

        return null;
    }

    /** Being constructed is the check: the names were validated above. */
    public function check(): void
    {
    }
}
