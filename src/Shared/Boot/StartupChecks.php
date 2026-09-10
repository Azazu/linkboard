<?php

declare(strict_types=1);

namespace App\Shared\Boot;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every tagged startup check. Public in the container and called from
 * App\Kernel::boot() so that a misconfiguration (an unknown name in
 * COUNTRY_RESOLVERS, for instance) fails the web process, the console, the
 * worker and the test kernel before anything is served — Symfony otherwise
 * instantiates services lazily (add-routing-rules, design decision 8).
 */
final readonly class StartupChecks
{
    /**
     * @param iterable<StartupCheckInterface> $checks
     */
    public function __construct(
        #[AutowireIterator('app.startup_check')]
        private iterable $checks,
    ) {
    }

    public function run(): void
    {
        foreach ($this->checks as $check) {
            $check->check();
        }
    }
}
