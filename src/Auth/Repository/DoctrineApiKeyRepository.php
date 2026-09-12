<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\ApiKeyRepositoryInterface;
use App\Auth\Entity\ApiKey;
use App\Auth\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineApiKeyRepository implements ApiKeyRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(ApiKey $key): void
    {
        $this->em->persist($key);
    }

    public function findById(Uuid $id): ?ApiKey
    {
        return $this->em->find(ApiKey::class, $id);
    }

    public function findByIdAndOwner(Uuid $id, User $owner): ?ApiKey
    {
        return $this->em->createQuery('SELECT k FROM App\Auth\Entity\ApiKey k WHERE k.id = :id AND k.owner = :owner')
            ->setParameter('id', $id)
            ->setParameter('owner', $owner)
            ->getOneOrNullResult();
    }

    public function findActiveByHash(string $keyHash, \DateTimeImmutable $now): ?ApiKey
    {
        return $this->em->createQuery('SELECT k FROM App\Auth\Entity\ApiKey k WHERE k.keyHash = :hash AND k.revokedAt IS NULL AND (k.expiresAt IS NULL OR k.expiresAt > :now)')
            ->setParameter('hash', $keyHash)
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->getOneOrNullResult();
    }

    public function listByOwner(User $owner, int $offset, int $limit): array
    {
        /** @var list<ApiKey> $keys */
        $keys = $this->em->createQuery('SELECT k FROM App\Auth\Entity\ApiKey k WHERE k.owner = :owner ORDER BY k.createdAt DESC, k.id DESC')
            ->setParameter('owner', $owner)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getResult();

        return $keys;
    }

    public function countByOwner(User $owner): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(k.id) FROM App\Auth\Entity\ApiKey k WHERE k.owner = :owner')
            ->setParameter('owner', $owner)
            ->getSingleScalarResult();
    }

    public function countActiveByOwner(User $owner, \DateTimeImmutable $now): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(k.id) FROM App\Auth\Entity\ApiKey k WHERE k.owner = :owner AND k.revokedAt IS NULL AND (k.expiresAt IS NULL OR k.expiresAt > :now)')
            ->setParameter('owner', $owner)
            ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE)
            ->getSingleScalarResult();
    }

    public function lockOwner(User $owner): void
    {
        $this->em->getConnection()->executeStatement('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $owner->getId()->toRfc4122()]);
    }

    public function touchLastUsed(Uuid $id, \DateTimeImmutable $now, \DateTimeImmutable $threshold): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE api_keys SET last_used_at = :now WHERE id = :id AND (last_used_at IS NULL OR last_used_at < :threshold)',
            ['id' => $id->toRfc4122(), 'now' => $now, 'threshold' => $threshold],
            ['now' => Types::DATETIMETZ_IMMUTABLE, 'threshold' => Types::DATETIMETZ_IMMUTABLE],
        );
    }
}
