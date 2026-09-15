<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

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

    /**
     * @return array<string, string>
     */
    private static function declared(): array
    {
        $root = \dirname(__DIR__, 2);
        $declared = [];
        foreach (['/.env.test', '/.env.test.local'] as $file) {
            if (is_file($root.$file)) {
                $declared = [...$declared, ...new Dotenv()->parse((string) file_get_contents($root.$file), $root.$file)];
            }
        }

        return $declared;
    }
}
