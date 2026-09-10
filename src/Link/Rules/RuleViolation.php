<?php

declare(strict_types=1);

namespace App\Link\Rules;

/**
 * One violation of the document shape; `path` is in array-access notation
 * relative to the document (`[rules][0][match][device][1]`, `''` = the root).
 */
final readonly class RuleViolation
{
    public function __construct(
        public string $path,
        public string $message,
    ) {
    }
}
