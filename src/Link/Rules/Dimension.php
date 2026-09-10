<?php

declare(strict_types=1);

namespace App\Link\Rules;

/**
 * The three matching dimensions of a rule (FR-RUL-2/4). `device` and `os`
 * keys of a match both belong to the device dimension.
 */
enum Dimension: string
{
    case Device = 'device';
    case Country = 'country';
    case Language = 'language';

    /**
     * @param list<string> $matchKeys
     */
    public static function ofMatchKeys(array $matchKeys): ?self
    {
        $dimensions = [];
        foreach ($matchKeys as $key) {
            $dimensions[match ($key) {
                'device', 'os' => self::Device->value,
                'country' => self::Country->value,
                'language' => self::Language->value,
                default => '?',
            }] = true;
        }
        unset($dimensions['?']);

        return 1 === \count($dimensions) ? self::from((string) array_key_first($dimensions)) : null;
    }
}
