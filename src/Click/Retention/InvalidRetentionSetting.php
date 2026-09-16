<?php

declare(strict_types=1);

namespace App\Click\Retention;

/**
 * A retention setting is not a whole number of months of at least one.
 *
 * Refused rather than coerced, and refused before anything is dropped: a window
 * of `0` puts the cutoff at this instant and makes the current, populated month
 * eligible; a negative one puts it in the future and makes every month
 * eligible; an unset variable reads as an empty string (change
 * stretch-partition-clicks, design decision 6). The message names the setting,
 * never a value that might be configuration.
 */
final class InvalidRetentionSetting extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $setting,
        string $value,
    ) {
        parent::__construct(\sprintf(
            '%s must be a whole number of months of at least 1, got %s.',
            $setting,
            '' === $value ? 'an empty value' : \sprintf('"%s"', $value),
        ));
    }
}
