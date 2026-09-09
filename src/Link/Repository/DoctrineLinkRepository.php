<?php

declare(strict_types=1);

namespace App\Link\Repository;

use App\Link\Entity\Link;
use App\Link\LinkListQuery;
use App\Link\LinkRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineLinkRepository implements LinkRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findById(Uuid $id): ?Link
    {
        return $this->em->find(Link::class, $id);
    }

    public function findBySlug(string $slug): ?Link
    {
        return $this->em->getRepository(Link::class)->findOneBy(['slug' => $slug]);
    }

    public function slugExists(string $slug): bool
    {
        return null !== $this->em->createQuery('SELECT l.id FROM App\Link\Entity\Link l WHERE l.slug = :slug')
            ->setParameter('slug', $slug)
            ->getOneOrNullResult();
    }

    public function add(Link $link): void
    {
        $this->em->persist($link);
    }

    public function remove(Link $link): void
    {
        $this->em->remove($link);
    }

    public function findPageForOwner(Uuid $ownerId, LinkListQuery $query, int $offset, int $limit): array
    {
        /** @var list<Link> $links */
        $links = $this->ordered($this->filtered($query)->andWhere('IDENTITY(l.owner) = :owner')->setParameter('owner', $ownerId), $query)
            ->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return $links;
    }

    public function countForOwner(Uuid $ownerId, LinkListQuery $query): int
    {
        return (int) $this->filtered($query)->select('COUNT(l.id)')
            ->andWhere('IDENTITY(l.owner) = :owner')->setParameter('owner', $ownerId)
            ->getQuery()->getSingleScalarResult();
    }

    public function findPage(LinkListQuery $query, int $offset, int $limit): array
    {
        /** @var list<Link> $links */
        $links = $this->ordered($this->filtered($query), $query)->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return $links;
    }

    public function count(LinkListQuery $query): int
    {
        return (int) $this->filtered($query)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
    }

    private function filtered(LinkListQuery $query): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->select('l')->from(Link::class, 'l');
        if (null !== $query->isActive) {
            $qb->andWhere('l.active = :active')->setParameter('active', $query->isActive);
        }
        if (null !== $query->slugContains && '' !== $query->slugContains) {
            // escape LIKE wildcards so the substring is taken literally (case-sensitive);
            // '!' as the escape character: PostgreSQL rejects a backslash escape string
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query->slugContains);
            $qb->andWhere("l.slug LIKE :slug ESCAPE '!'")->setParameter('slug', '%'.$escaped.'%');
        }

        return $qb;
    }

    private function ordered(QueryBuilder $qb, LinkListQuery $query): QueryBuilder
    {
        $field = 'clickCount' === $query->orderField ? 'l.clickCount' : 'l.createdAt';

        return $qb->orderBy($field, strtoupper($query->direction))->addOrderBy('l.id', 'DESC');
    }
}
