<?php

declare(strict_types=1);

namespace App\Link;

use App\Link\Entity\Link;
use Symfony\Component\Uid\Uuid;

interface LinkRepositoryInterface
{
    public function findById(Uuid $id): ?Link;

    /** Exact, case-sensitive match. */
    public function findBySlug(string $slug): ?Link;

    public function slugExists(string $slug): bool;

    /** Schedules the entity; the caller's unit of work flushes. */
    public function add(Link $link): void;

    public function remove(Link $link): void;

    /**
     * @return list<Link>
     */
    public function findPageForOwner(Uuid $ownerId, LinkListQuery $query, int $offset, int $limit): array;

    public function countForOwner(Uuid $ownerId, LinkListQuery $query): int;

    /**
     * @return list<Link> every user's links (admin listing)
     */
    public function findPage(LinkListQuery $query, int $offset, int $limit): array;

    public function count(LinkListQuery $query): int;
}
