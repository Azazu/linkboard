<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineUserRepository implements UserRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->createQuery('SELECT u FROM App\Auth\Entity\User u WHERE LOWER(u.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->getOneOrNullResult();
    }

    public function findById(Uuid $id): ?User
    {
        return $this->em->find(User::class, $id);
    }

    public function add(User $user): void
    {
        $this->em->persist($user);
    }

    public function findPage(int $offset, int $limit): array
    {
        /** @var list<User> $users */
        $users = $this->em->createQuery('SELECT u FROM App\Auth\Entity\User u ORDER BY u.createdAt DESC, u.id DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getResult();

        return $users;
    }

    public function count(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(u.id) FROM App\Auth\Entity\User u')->getSingleScalarResult();
    }
}
