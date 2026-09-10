<?php

declare(strict_types=1);

namespace App;

use App\Shared\Boot\StartupChecks;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Every first boot runs the startup checks (App\Shared\Boot\StartupChecks):
     * configuration that the container cannot validate at compile time (runtime
     * env vars) fails here, before a request, command or message is handled.
     */
    public function boot(): void
    {
        $wasBooted = $this->booted;
        parent::boot();
        if ($wasBooted) {
            return;
        }
        \assert(null !== $this->container);
        if (!$this->container->has(StartupChecks::class)) {
            return; // a container compiled before the checks existed (stale cache during the upgrade's own cache:clear)
        }
        $checks = $this->container->get(StartupChecks::class);
        \assert($checks instanceof StartupChecks);
        $checks->run();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
