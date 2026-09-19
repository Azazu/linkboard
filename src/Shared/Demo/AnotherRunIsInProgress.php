<?php

declare(strict_types=1);

namespace App\Shared\Demo;

/**
 * Another `app:demo:seed` holds the seed's advisory lock (spec demo-data,
 * "Guards and re-runs"). Thrown from inside the transaction so that the
 * rollback is the guarantee that nothing was written, and caught outside so
 * that a refused run reads as a refusal rather than as a failure.
 */
final class AnotherRunIsInProgress extends \RuntimeException
{
}
