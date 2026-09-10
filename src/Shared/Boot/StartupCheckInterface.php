<?php

declare(strict_types=1);

namespace App\Shared\Boot;

/**
 * A configuration invariant verified on every kernel boot. Tag implementations
 * `app.startup_check`; StartupChecks instantiates them from App\Kernel::boot(),
 * so a constructor that validates its configuration is already the check.
 */
interface StartupCheckInterface
{
    public function check(): void;
}
