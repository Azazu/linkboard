<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * The Redis memory could not perform one operation; `$operation` is one of
 * `connect`, `auth`, `token`, `remember`, `deny`, `consult` (design decision 4).
 * Carries class names only — never the key, its hash or a Redis value.
 */
final class MemoryUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
        \Throwable $previous,
    ) {
        parent::__construct(\sprintf('probe memory %s failed: %s', $operation, $previous::class), 0, $previous);
    }
}
