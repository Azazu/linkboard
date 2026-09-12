<?php

declare(strict_types=1);

namespace App\Auth\ApiKey;

/**
 * A freshly generated API key: the plaintext to hand out exactly once, and
 * what the database stores instead of it (design decision 2).
 */
final readonly class GeneratedKey
{
    public function __construct(
        #[\SensitiveParameter]
        public string $plaintext,
        public string $hash,
        public string $prefix,
    ) {
    }
}
