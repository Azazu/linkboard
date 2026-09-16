<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\TestEnvironment;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A test process resolves what `.env.test` declares, and only that (change
 * harden-quality-and-docs, design decision 6).
 *
 * The defect this guards against: `docker-compose.yml` passes `.env` into the
 * php container's process environment, and Symfony's `Dotenv` never overrides
 * a real environment variable with a file's — so inside the container the four
 * variables `.env` also sets used to win, and the suite ran with the
 * development salt and passphrase while CI ran with the test ones. Ten
 * redirect tests failed locally and passed in CI.
 *
 * The other half matters just as much: CI sets its own connection variables
 * and `.env.test` names none of them, so they must stay exactly as the process
 * received them.
 */
#[CoversNothing]
final class TestEnvironmentTest extends TestCase
{
    public function testEveryVariableTheTestEnvironmentDeclaresIsInForce(): void
    {
        $declared = self::declared();
        self::assertNotSame([], $declared, 'the file parsed to nothing, so this test proves nothing');

        foreach ($declared as $name => $value) {
            self::assertSame($value, $_ENV[$name] ?? null, "$name comes from .env.test");
            self::assertSame($value, getenv($name), "$name is in the process environment too, for anything that shells out");
        }
    }

    public function testTheFourThatUsedToLoseAreNamedHere(): void
    {
        // named rather than derived: if one is dropped from .env.test, this
        // fails instead of the set quietly shrinking
        foreach (['APP_SECRET', 'JWT_PASSPHRASE', 'VISITOR_HASH_SALT', 'COUNTRY_RESOLVERS'] as $name) {
            self::assertArrayHasKey($name, self::declared(), "$name is declared by .env.test");
        }

        self::assertSame('fixed', $_ENV['COUNTRY_RESOLVERS'] ?? null, 'the fixed IP→country map, not the geolite2 chain');
        self::assertSame('test-only-visitor-salt', $_ENV['VISITOR_HASH_SALT'] ?? null);
    }

    public function testNothingIsSetToAnEmptyValueByTheMechanism(): void
    {
        // an empty APP_SECRET or salt would be worse than the defect
        foreach (self::declared() as $name => $value) {
            self::assertNotSame('', $value, "$name is declared with a value");
        }
    }

    public function testAVariableTheFileDoesNotDeclareIsLeftAlone(): void
    {
        // CI sets these deliberately; .env.test names none of them, so the
        // bootstrap must not touch them
        $declared = self::declared();
        foreach (['DATABASE_URL', 'REDIS_URL', 'LOCK_DSN', 'MESSENGER_TRANSPORT_DSN'] as $name) {
            self::assertArrayNotHasKey($name, $declared, "$name is not .env.test's to decide");
        }

        self::assertIsString($_ENV['DATABASE_URL'] ?? null, 'and it still reaches the suite from wherever it was set');
    }

    public function testAValueThatRefersToAnEarlierFileResolvesAgainstIt(): void
    {
        // The guarantee: each file is published before the next is parsed, so
        // `.env.test.local` can build on `.env.test`. Resolved in a subprocess
        // against a throwaway root, because the assertion needs an empty
        // JWT_PASSPHRASE in the real environment — the container's situation —
        // and must not touch this process's own variables.
        //
        // Without the publication between files the reference falls back to
        // that empty real value and this resolves to "-tail" instead
        // (Gate 2 confirmation 1, finding 1).
        $root = sys_get_temp_dir().'/lb-env-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root), 'a throwaway root for the two files');

        try {
            file_put_contents($root.'/.env', "JWT_PASSPHRASE=\n");
            file_put_contents($root.'/.env.test', "JWT_PASSPHRASE=base\n");
            file_put_contents($root.'/.env.test.local', 'JWT_PASSPHRASE="${JWT_PASSPHRASE}-tail"'."\n");
            file_put_contents($root.'/resolve.php', \sprintf(
                '<?php require %s; echo %s::apply(__DIR__)[%s] ?? %s;',
                var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true),
                '\\'.TestEnvironment::class,
                var_export('JWT_PASSPHRASE', true),
                var_export('', true),
            ));

            $process = new Process(['php', $root.'/resolve.php'], env: ['APP_ENV' => 'test', 'JWT_PASSPHRASE' => '']);
            $process->mustRun();

            self::assertSame('base-tail', $process->getOutput(), '.env.test.local resolves ${JWT_PASSPHRASE} against .env.test');
        } finally {
            foreach (glob($root.'/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($root.'/.env*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    /**
     * The map the bootstrap itself applied, from the code it ran — not a
     * second implementation of the precedence. `apply()` is idempotent: it
     * re-publishes the same values this process already holds.
     *
     * @return array<string, string>
     */
    private static function declared(): array
    {
        return TestEnvironment::apply(\dirname(__DIR__, 2));
    }
}
