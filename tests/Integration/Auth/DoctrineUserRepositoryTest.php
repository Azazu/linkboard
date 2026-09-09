<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\Entity\User;
use App\Auth\Repository\DoctrineUserRepository;
use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(DoctrineUserRepository::class)]
final class DoctrineUserRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testFindByEmailIsCaseInsensitive(): void
    {
        UserFactory::createOne(['email' => 'Ann@Example.com']);
        $repository = self::getContainer()->get(UserRepositoryInterface::class);

        $found = $repository->findByEmail('ann@example.COM');

        self::assertInstanceOf(User::class, $found);
        self::assertSame('Ann@Example.com', $found->getEmail());
        self::assertNull($repository->findByEmail('nobody@example.com'));
    }

    public function testDuplicateEmailDifferingOnlyByCaseIsRejectedByTheDatabase(): void
    {
        UserFactory::createOne(['email' => 'ann@example.com']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(UserRepositoryInterface::class);

        $repository->add(new User('Ann@Example.com', UserFactory::passwordHash(), new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testPageIsNewestFirstAndCountMatches(): void
    {
        UserFactory::createMany(3);
        $repository = self::getContainer()->get(UserRepositoryInterface::class);

        $page = $repository->findPage(0, 2);

        self::assertCount(2, $page);
        self::assertSame(3, $repository->count());
        self::assertGreaterThanOrEqual($page[1]->getCreatedAt(), $page[0]->getCreatedAt());
    }
}
