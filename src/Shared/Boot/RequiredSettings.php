<?php

declare(strict_types=1);

namespace App\Shared\Boot;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Spec deployment, "A missing or default setting stops the boot" (change
 * stretch-public-hosting, design decision 6).
 *
 * The set is enumerated **by consumer**, not by what looks like a password.
 * Naming "the database and Redis credentials" would not have been a
 * specification: Postgres is reached through `DATABASE_URL` and Redis through
 * three independent settings, so a check that examined one of them would leave
 * the others carrying the committed values (Gate 1 round 1, finding 5).
 *
 * Three states fail, and the third is the one that actually happens: an
 * operator who copies `.env` onto the host gets a working instance with a
 * published salt and a published database password, and nothing else would
 * complain. The committed values are read from `.env` itself rather than
 * copied into PHP, so this cannot fall out of step with the file it is about.
 *
 * Armed by the instance declaring itself a **deployment** (`DEPLOYMENT=true`),
 * not by `APP_ENV=prod`. That was the first implementation and it was wrong
 * for this repository: two legitimate local runs use the prod kernel without
 * being deployments — the benchmark recipe in `docs/how-to/benchmarks.md`, and
 * `HealthDeepProdTest`, which spawns a prod process to read the log records a
 * 404 withholds. Arming on `prod` refused both, and the only ways to keep them
 * working were to point them at other credentials (churning a passing suite)
 * or to append something cosmetic to a DSN so the strings differ (gaming the
 * check).
 *
 * The distinction that matters is not the kernel's environment but whether
 * this instance is serving the public, and the thing that knows that is the
 * deployment definition. `DEPLOYMENT` is therefore set in
 * `docker-compose.prod.yml` — committed, reviewed, and covering the web
 * process, the worker and the scheduler because they share it — and defaults
 * to `false` everywhere else. It is an opt-IN to being checked, not an off
 * switch: nothing an operator writes in `.env.local` disarms it, and a test
 * asserts that the production compose file sets it, so a deployment cannot
 * quietly arrive without it.
 */
#[AutoconfigureTag('app.startup_check')]
final readonly class RequiredSettings implements StartupCheckInterface
{
    /**
     * Every setting through which a credential or a secret actually reaches
     * the application. Adding a consumer means adding it here, and the test
     * asserts the count so that a silent omission fails.
     *
     * @var list<string>
     */
    public const array NAMES = [
        'APP_SECRET',
        'JWT_PASSPHRASE',
        'VISITOR_HASH_SALT',
        'DATABASE_URL',
        'REDIS_URL',
        'LOCK_DSN',
        'MESSENGER_TRANSPORT_DSN',
    ];

    public function __construct(
        #[Autowire(env: 'bool:DEPLOYMENT')]
        private bool $isDeployment,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function check(): void
    {
        if (!$this->isDeployment) {
            return;
        }

        $committed = self::committedDefaults($this->projectDir.'/.env');

        foreach (self::NAMES as $name) {
            $problem = self::problemWith($name, self::valueOf($name), $committed[$name] ?? null);
            if (null !== $problem) {
                throw $problem;
            }
        }
    }

    /**
     * The decision itself, pure so that every setting and every state can be
     * driven from a test without a kernel.
     */
    public static function problemWith(string $name, ?string $value, ?string $committed): ?MisconfiguredSetting
    {
        if (null === $value || '' === trim($value)) {
            return MisconfiguredSetting::unset($name);
        }

        if (null !== $committed && '' !== trim($committed) && $value === $committed) {
            return MisconfiguredSetting::stillTheCommittedDefault($name);
        }

        return null;
    }

    /**
     * The committed development defaults, read from the repository's own
     * `.env` — the file the image carries for exactly this reason.
     *
     * @return array<string, string>
     */
    public static function committedDefaults(string $path): array
    {
        if (!is_file($path) || false === $contents = file_get_contents($path)) {
            return [];
        }

        try {
            return (new Dotenv())->parse($contents, $path);
        } catch (\Throwable) {
            // A `.env` this cannot parse is not a reason to refuse a boot the
            // rest of the configuration allows; the unset/empty half still runs.
            return [];
        }
    }

    private static function valueOf(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;

        return \is_string($value) ? $value : null;
    }
}
