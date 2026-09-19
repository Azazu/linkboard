<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Boot\MisconfiguredSetting;
use App\Shared\Boot\RequiredSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec deployment, "A missing or default setting stops the boot".
 *
 * Every case here boots a real `prod` kernel, because the guarantee is about
 * the boot: `StartupChecks` runs from `App\Kernel::boot()`, so the web
 * process, the worker and the scheduler all refuse for the same reason rather
 * than each having to remember (change stretch-public-hosting, design
 * decision 6).
 */
#[CoversClass(RequiredSettings::class)]
final class RequiredSettingsTest extends KernelTestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    /**
     * A `prod` kernel does not rebuild its container when the source changes —
     * that is the point of it — so a cache compiled before this check existed
     * would boot without it and every case below would pass for the wrong
     * reason. Dropped once per class; the first boot pays for the compile.
     */
    public static function setUpBeforeClass(): void
    {
        $cache = \dirname(__DIR__, 3).'/var/cache/prod';
        if (is_dir($cache)) {
            exec('rm -rf '.escapeshellarg($cache));
        }
    }

    protected function setUp(): void
    {
        foreach ([...RequiredSettings::NAMES, 'COUNTRY_RESOLVERS', 'DEPLOYMENT'] as $name) {
            $this->saved[$name] = \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : null;
        }
    }

    protected function tearDown(): void
    {
        // parent::tearDown() can boot the kernel again to reset services, so
        // the acceptable values have to still be in place when it runs: the
        // restore happens after it, not before.
        self::ensureKernelShutdown();
        parent::tearDown();

        foreach ($this->saved as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $value;
                $_ENV[$name] = $value;
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function everySettingInEveryBadState(): iterable
    {
        foreach (RequiredSettings::NAMES as $name) {
            yield "$name unset" => [$name, 'unset'];
            yield "$name empty" => [$name, 'empty'];
            yield "$name still the committed default" => [$name, 'committed'];
        }
    }

    #[DataProvider('everySettingInEveryBadState')]
    public function testABadSettingStopsTheBootAndIsNamed(string $spoiled, string $state): void
    {
        $committed = RequiredSettings::committedDefaults(\dirname(__DIR__, 3).'/.env');
        self::arrangeAcceptableSettings();

        $value = match ($state) {
            'unset' => null,
            'empty' => '',
            'committed' => $committed[$spoiled] ?? null,
            default => self::fail("unknown state $state"),
        };
        if ('committed' === $state && (null === $value || '' === $value)) {
            // APP_SECRET and JWT_PASSPHRASE are committed EMPTY, so for them
            // this state is the empty one and is covered by its own case. The
            // assertion below still runs, so the case is not silently skipped.
            $value = '';
        }
        if (null === $value) {
            unset($_SERVER[$spoiled], $_ENV[$spoiled]);
        } else {
            $_SERVER[$spoiled] = $value;
            $_ENV[$spoiled] = $value;
        }

        try {
            self::bootKernel(['environment' => 'prod', 'debug' => false]);
            self::fail("the boot was allowed with $spoiled $state");
        } catch (MisconfiguredSetting $e) {
            self::assertStringContainsString($spoiled, $e->getMessage(), 'the failure names the setting');
            if (null !== $value && '' !== $value) {
                self::assertStringNotContainsString($value, $e->getMessage(), 'the failure does not disclose the value');
            }
        }
    }

    public function testAProperlyConfiguredProductionInstanceBoots(): void
    {
        self::arrangeAcceptableSettings();

        self::bootKernel(['environment' => 'prod', 'debug' => false]);

        self::assertSame('prod', self::$kernel?->getEnvironment());
    }

    public function testTheSetIsTheOneTheApplicationConsumes(): void
    {
        // A consumer added without being named here is the hole finding 5 of
        // Gate 1 round 1 was about: Redis is reached through three independent
        // settings, and checking one would leave two carrying committed values.
        self::assertSame([
            'APP_SECRET',
            'JWT_PASSPHRASE',
            'VISITOR_HASH_SALT',
            'DATABASE_URL',
            'REDIS_URL',
            'LOCK_DSN',
            'MESSENGER_TRANSPORT_DSN',
        ], RequiredSettings::NAMES);
        self::assertCount(7, RequiredSettings::NAMES);
    }

    public function testAnInstanceThatIsNotADeploymentIsNotArmed(): void
    {
        // The committed defaults are what development, the test suite, the
        // benchmark recipe and the prod-kernel probe all run on. None of them
        // is a deployment, and arming there would refuse the stack this
        // repository is developed on.
        (new RequiredSettings(false, \dirname(__DIR__, 3)))->check();

        // reaching here is the assertion: the check would have thrown
        self::expectNotToPerformAssertions();
    }

    public function testADeploymentOnACommittedDefaultIsRefused(): void
    {
        // The same instance, told it is a deployment. The setting left at its
        // committed value is chosen HERE rather than inherited from whatever
        // the process happens to carry: locally the connection strings come
        // from `.env` and are committed defaults, while CI sets all four
        // explicitly, so a test that relied on ambient state passed on one
        // machine and failed on the other — which is the divergence
        // `App\Tests\TestEnvironment` exists because of.
        $committed = RequiredSettings::committedDefaults(\dirname(__DIR__, 3).'/.env');
        $salt = $committed['VISITOR_HASH_SALT'] ?? '';
        self::assertNotSame('', $salt, '.env commits a development salt for this to be about');

        self::arrangeAcceptableSettings();
        $_SERVER['VISITOR_HASH_SALT'] = $salt;
        $_ENV['VISITOR_HASH_SALT'] = $salt;

        try {
            (new RequiredSettings(true, \dirname(__DIR__, 3)))->check();
            self::fail('a deployment still carrying a committed default was allowed');
        } catch (MisconfiguredSetting $e) {
            self::assertStringContainsString('VISITOR_HASH_SALT', $e->getMessage());
            self::assertStringNotContainsString($salt, $e->getMessage());
        }
    }

    public function testADeploymentWithEverySettingItsOwnIsAllowed(): void
    {
        // the other half, and the reason the case above cannot pass by
        // accident: with nothing left at a committed value, nothing refuses
        self::arrangeAcceptableSettings();

        (new RequiredSettings(true, \dirname(__DIR__, 3)))->check();

        self::expectNotToPerformAssertions();
    }

    public function testTheProductionComposeFileArmsIt(): void
    {
        // The marker is an opt-in to being checked, so the hole to close is a
        // deployment that never sets it. The deployment definition is
        // committed, so the guard is a test over that file rather than trust.
        $compose = \dirname(__DIR__, 3).'/docker-compose.prod.yml';
        self::assertFileExists($compose, 'the production stack defines the deployment');
        self::assertMatchesRegularExpression(
            '/^\s*DEPLOYMENT:\s*["\']?true["\']?\s*$/m',
            (string) file_get_contents($compose),
            'docker-compose.prod.yml must arm the required-settings check',
        );
    }

    /**
     * Values that are acceptable to the check: set, non-empty, and different
     * from what `.env` commits.
     */
    private static function arrangeAcceptableSettings(): void
    {
        $acceptable = [
            'APP_SECRET' => 'test-only-not-the-committed-default',
            'JWT_PASSPHRASE' => 'test-only-passphrase',
            'VISITOR_HASH_SALT' => 'test-only-salt',
            'DATABASE_URL' => 'postgresql://u:p@postgres:5432/db?serverVersion=16&charset=utf8',
            'REDIS_URL' => 'redis://:p@redis:6379',
            'LOCK_DSN' => 'redis://:p@redis:6379',
            'MESSENGER_TRANSPORT_DSN' => 'redis://:p@redis:6379/messages',
            // prod does not know the test suite's fixed-map resolver
            'COUNTRY_RESOLVERS' => 'header',
            // what arms the check: this instance declares itself a deployment
            'DEPLOYMENT' => 'true',
        ];
        foreach ($acceptable as $name => $value) {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
    }
}
