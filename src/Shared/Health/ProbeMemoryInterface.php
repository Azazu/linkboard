<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * The Redis memory of verified admin keys (design decision 4). One connection
 * per request: connect(), then exactly one pre-lookup token() read and one
 * post-lookup command — remember(), deny() or consult() — then close().
 * Every method throws MemoryUnavailable naming the operation that failed.
 */
interface ProbeMemoryInterface
{
    /** connect + AUTH (or PING when the URL has no password): the fixed first two operations. */
    public function connect(): void;

    /** The current denial token of the key, `''` when none — read before the lookup. */
    public function token(#[\SensitiveParameter] string $hash): string;

    /**
     * Stores the verification only if the denial token is still $token (the
     * one read before the lookup); returns whether it was stored.
     */
    public function remember(#[\SensitiveParameter] string $hash, string $token, ?\DateTimeImmutable $expiresAt, \DateTimeImmutable $verifiedAt): bool;

    /** A denied answer: a fresh random denial token, the remembered verification dropped. */
    public function deny(#[\SensitiveParameter] string $hash): void;

    /** Whether a verification less than the TTL old (by its own time) of an unexpired key is remembered. */
    public function consult(#[\SensitiveParameter] string $hash, \DateTimeImmutable $now): bool;

    public function close(): void;
}
