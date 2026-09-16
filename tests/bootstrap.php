<?php

declare(strict_types=1);

use App\Tests\TestEnvironment;

require dirname(__DIR__).'/vendor/autoload.php';

/*
 * The resolution itself lives in `App\Tests\TestEnvironment` because
 * `scripts/test-jwt-passphrase.php` — which `make jwt-keys` uses to encrypt the
 * test keypair — has to produce the same values this suite authenticates with.
 * Two implementations of "the same precedence" drifted twice; one is shared.
 */
TestEnvironment::apply(dirname(__DIR__));

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
