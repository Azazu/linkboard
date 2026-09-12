<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * A bounded Redis operation of the probe failed or timed out; `$operation`
 * names which (`connect`, `auth`). The message carries class names only.
 */
final class RedisOperationFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
        \Throwable $previous,
    ) {
        parent::__construct(\sprintf('Redis %s failed: %s', $operation, $previous::class), 0, $previous);
    }
}
