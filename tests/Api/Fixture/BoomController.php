<?php

declare(strict_types=1);

namespace App\Tests\Api\Fixture;

/**
 * Test-only route target (config/routes.yaml, when@test): throws an
 * unexpected exception under /api so the 500 problem-details rendering can
 * be asserted without a real defect.
 */
final class BoomController
{
    public function __invoke(): never
    {
        throw new \RuntimeException('secret detail that must not leak: /app/src/Secret.php');
    }
}
