<?php

declare(strict_types=1);

/*
 * The JWT passphrase the test environment declares, resolved by the very code
 * `tests/bootstrap.php` runs: `App\Tests\TestEnvironment::apply()`.
 *
 * It exists so that `scripts/test-jwt-keys.sh` does not have to approximate
 * dotenv syntax in shell, and so that the generator of the test keypair cannot
 * resolve a different value from the suite that authenticates with it. Two
 * earlier attempts at "the same precedence, implemented twice" both diverged —
 * a `sed` version kept the trailing comment of `JWT_PASSPHRASE=x # comment`,
 * and a Dotenv version that published nothing between the two files resolved
 * `"${JWT_PASSPHRASE}-tail"` in `.env.test.local` against the process
 * environment instead of against `.env.test` (change harden-quality-and-docs,
 * Gate 2 round 1 and confirmation 1, finding 1).
 *
 * Prints the value and nothing else; prints nothing when the files do not
 * declare one.
 */

use App\Tests\TestEnvironment;

$root = \dirname(__DIR__);
require $root.'/vendor/autoload.php';

echo TestEnvironment::apply($root)['JWT_PASSPHRASE'] ?? '';
