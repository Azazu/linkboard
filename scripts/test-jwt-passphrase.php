<?php

declare(strict_types=1);

/*
 * The JWT passphrase the test environment declares, resolved exactly as
 * `tests/bootstrap.php` resolves every test variable: `.env.test`, then
 * `.env.test.local` if it exists, parsed by Symfony's own Dotenv.
 *
 * It exists so that `scripts/test-jwt-keys.sh` does not have to approximate
 * dotenv syntax in shell. A `sed` version of this kept the trailing comment of
 * `JWT_PASSPHRASE=x # comment`, which would have encrypted the test key with a
 * different password than the suite authenticates with (change
 * harden-quality-and-docs, Gate 2 round 1, finding 1).
 *
 * Prints the value and nothing else; prints nothing when the files do not
 * declare one.
 */

use Symfony\Component\Dotenv\Dotenv;

$root = \dirname(__DIR__);
require $root.'/vendor/autoload.php';

$value = '';
foreach ([$root.'/.env.test', $root.'/.env.test.local'] as $file) {
    if (!is_file($file)) {
        continue;
    }
    $parsed = new Dotenv()->parse((string) file_get_contents($file), $file);
    if (\array_key_exists('JWT_PASSPHRASE', $parsed)) {
        $value = $parsed['JWT_PASSPHRASE'];
    }
}

echo $value;
