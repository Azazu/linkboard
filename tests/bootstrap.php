<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);

(new Dotenv())->bootEnv($root.'/.env');

/*
 * `bootEnv` never overrides a variable that is already in the real
 * environment — by design, so a deployment's configuration wins over a
 * committed file. `docker-compose.yml` passes `.env` to the php service, which
 * puts every development value into the process environment, so inside the
 * container `.env.test` lost on every variable `.env` also sets: APP_SECRET,
 * JWT_PASSPHRASE, VISITOR_HASH_SALT and COUNTRY_RESOLVERS. The suite ran
 * locally with the development salt, the development passphrase and the
 * production-shaped resolver chain while CI — which sets none of them — ran
 * with what this file declares. Ten redirect tests failed locally and passed
 * in CI for exactly that reason (change harden-quality-and-docs, design
 * decision 6).
 *
 * So a test process takes `.env.test` as the authority for the variables that
 * file declares, and for nothing else: CI sets DATABASE_URL, REDIS_URL,
 * LOCK_DSN and MESSENGER_TRANSPORT_DSN deliberately and this file names none
 * of them, so they stay CI's. `tests/Integration/TestEnvironmentTest.php`
 * asserts both halves of that sentence.
 */
foreach (['/.env.test', '/.env.test.local'] as $file) {
    if (!is_file($root.$file)) {
        continue;
    }
    foreach ((new Dotenv())->parse((string) file_get_contents($root.$file), $root.$file) as $name => $value) {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
