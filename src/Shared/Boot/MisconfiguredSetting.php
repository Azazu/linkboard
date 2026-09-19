<?php

declare(strict_types=1);

namespace App\Shared\Boot;

/**
 * A setting a production deployment requires is unset, empty, or still equal
 * to the value committed for local development (spec deployment, "A missing or
 * default setting stops the boot").
 *
 * The message names the setting and never the value it found: a boot failure
 * is read by whoever can see the logs, which is not always whoever may see the
 * credential.
 */
final class MisconfiguredSetting extends \RuntimeException
{
    public static function unset(string $name): self
    {
        return new self(\sprintf(
            '%s is not set. A production deployment requires it; generate one on the host and reference it by name (docs/how-to/deploy.md).',
            $name,
        ));
    }

    public static function stillTheCommittedDefault(string $name): self
    {
        return new self(\sprintf(
            '%s still holds the value committed in .env as a local development default. That value is public, so it is not a credential; generate one for this host (docs/how-to/deploy.md).',
            $name,
        ));
    }
}
