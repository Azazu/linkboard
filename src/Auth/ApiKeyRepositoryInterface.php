<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Entity\ApiKey;
use App\Auth\Entity\User;
use Symfony\Component\Uid\Uuid;

interface ApiKeyRepositoryInterface
{
    /** Persists a new key (the caller flushes). */
    public function add(ApiKey $key): void;

    public function findById(Uuid $id): ?ApiKey;

    /** A key by id that belongs to $owner — null for an unknown id and for another user's key alike. */
    public function findByIdAndOwner(Uuid $id, User $owner): ?ApiKey;

    /** The key whose hash equals $keyHash, if it is neither revoked nor expired at $now. */
    public function findActiveByHash(string $keyHash, \DateTimeImmutable $now): ?ApiKey;

    /**
     * @return list<ApiKey> newest first
     */
    public function listByOwner(User $owner, int $offset, int $limit): array;

    public function countByOwner(User $owner): int;

    public function countActiveByOwner(User $owner, \DateTimeImmutable $now): int;

    /**
     * Locks the owner's user row for the current transaction so concurrent key
     * creations for one owner serialise (design decision 5). Must run inside a
     * transaction the caller opened.
     */
    public function lockOwner(User $owner): void;

    /**
     * Sets last_used_at to $now when it is null or older than $threshold — one
     * conditional statement, so a key used many times a minute is written once.
     */
    public function touchLastUsed(Uuid $id, \DateTimeImmutable $now, \DateTimeImmutable $threshold): void;
}
