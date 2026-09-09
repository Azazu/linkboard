<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Entity\User;
use Symfony\Component\Uid\Uuid;

interface UserRepositoryInterface
{
    /** Case-insensitive lookup: the unique index is on lower(email). */
    public function findByEmail(string $email): ?User;

    public function findById(Uuid $id): ?User;

    /** Schedules the entity; the caller's unit of work flushes. */
    public function add(User $user): void;

    /**
     * @return list<User> ordered by creation time, newest first
     */
    public function findPage(int $offset, int $limit): array;

    public function count(): int;
}
