<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Component\Dotenv\Dotenv;

/**
 * The single resolution of the test environment.
 *
 * `tests/bootstrap.php` and `scripts/test-jwt-passphrase.php` both call this,
 * so the suite and the generator of the test JWT keypair cannot disagree about
 * a value. They used to: the generator resolved the passphrase with a parser of
 * its own, which diverged on a trailing comment (Gate 2 round 1, finding 1) and
 * then, once it used Dotenv, still diverged on a reference across the two files
 * because it published nothing between them (Gate 2 confirmation 1, finding 1).
 * Sharing the code is the only form of "same resolution" that cannot drift.
 */
final class TestEnvironment
{
    /**
     * Applies `.env` through Symfony's own boot sequence, then re-applies the
     * variables `.env.test` and `.env.test.local` declare — and only those.
     *
     * `bootEnv` never overrides a variable that is already in the real
     * environment, by design, so a deployment's configuration wins over a
     * committed file. `docker-compose.yml` passes `.env` to the php service,
     * which puts every development value into the process environment, so
     * inside the container `.env.test` lost on every variable `.env` also sets:
     * APP_SECRET, JWT_PASSPHRASE, VISITOR_HASH_SALT and COUNTRY_RESOLVERS. The
     * suite ran locally with the development salt, the development passphrase
     * and the production-shaped resolver chain while CI — which sets none of
     * them — ran with what the file declares. Ten redirect tests failed locally
     * and passed in CI for exactly that reason (change harden-quality-and-docs,
     * design decision 6).
     *
     * A test process therefore takes `.env.test` as the authority for the
     * variables that file declares, and for nothing else: CI sets DATABASE_URL,
     * REDIS_URL, LOCK_DSN and MESSENGER_TRANSPORT_DSN deliberately and the file
     * names none of them, so they stay CI's.
     * `tests/Integration/TestEnvironmentTest.php` asserts both halves of that
     * sentence.
     *
     * Each file is published to `$_ENV`, `$_SERVER` and `putenv()` before the
     * next one is parsed, so a value in `.env.test.local` that refers to one in
     * `.env.test` resolves against it — Dotenv resets its own values per parse
     * and falls back to the process environment.
     *
     * @return array<string, string> the variables those files declared, in force
     */
    public static function apply(string $root): array
    {
        new Dotenv()->bootEnv($root.'/.env');

        $declared = [];
        foreach (['/.env.test', '/.env.test.local'] as $file) {
            if (!is_file($root.$file)) {
                continue;
            }

            foreach (new Dotenv()->parse((string) file_get_contents($root.$file), $root.$file) as $name => $value) {
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
                putenv($name.'='.$value);
                $declared[$name] = $value;
            }
        }

        return $declared;
    }
}
