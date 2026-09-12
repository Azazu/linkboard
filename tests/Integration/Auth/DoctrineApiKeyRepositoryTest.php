<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\ApiKeyRepositoryInterface;
use App\Auth\Entity\ApiKey;
use App\Auth\Repository\DoctrineApiKeyRepository;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec api-keys against PostgreSQL: the hash lookup ignores revoked and
 * expired keys, the once-per-minute last_used_at write, the active count, the
 * owner scope, the FK cascade, and the api_keys migration round trip.
 */
#[CoversClass(DoctrineApiKeyRepository::class)]
final class DoctrineApiKeyRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testFindActiveByHashIgnoresRevokedExpiredAndUnknown(): void
    {
        $now = new \DateTimeImmutable();
        $fresh = ApiKeyFactory::plaintext();
        $revoked = ApiKeyFactory::plaintext();
        $expired = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($fresh)->create();
        ApiKeyFactory::new()->forPlaintext($revoked)->revoked()->create();
        ApiKeyFactory::new()->forPlaintext($expired)->expired()->create();
        $repository = $this->repository();

        $found = $repository->findActiveByHash(hash('sha256', $fresh), $now);
        self::assertInstanceOf(ApiKey::class, $found);
        self::assertSame(substr($fresh, 0, 8), $found->getPrefix());
        self::assertNull($repository->findActiveByHash(hash('sha256', $revoked), $now), 'revoked');
        self::assertNull($repository->findActiveByHash(hash('sha256', $expired), $now), 'expired');
        self::assertNull($repository->findActiveByHash(hash('sha256', ApiKeyFactory::plaintext()), $now), 'unknown');
    }

    public function testTouchLastUsedWritesOncePerMinute(): void
    {
        $key = ApiKeyFactory::createOne();
        $repository = $this->repository();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $t0 = new \DateTimeImmutable('2026-09-12T12:00:00Z');

        $repository->touchLastUsed($key->getId(), $t0, $t0->modify('-60 seconds'));
        self::assertEquals($t0, $this->lastUsedAt($key->getId()), 'first use sets it');

        $t1 = $t0->modify('+30 seconds');
        $repository->touchLastUsed($key->getId(), $t1, $t1->modify('-60 seconds'));
        self::assertEquals($t0, $this->lastUsedAt($key->getId()), 'within the minute: unchanged');

        $t2 = $t0->modify('+61 seconds');
        $repository->touchLastUsed($key->getId(), $t2, $t2->modify('-60 seconds'));
        self::assertEquals($t2, $this->lastUsedAt($key->getId()), 'after a minute: updated');
        $em->clear();
    }

    public function testCountActiveByOwnerExcludesRevokedAndExpired(): void
    {
        $owner = UserFactory::createOne();
        $other = UserFactory::createOne();
        ApiKeyFactory::createMany(3, ['owner' => $owner]);
        ApiKeyFactory::new()->revoked()->create(['owner' => $owner]);
        ApiKeyFactory::new()->expired()->create(['owner' => $owner]);
        ApiKeyFactory::createMany(2, ['owner' => $other]);
        $repository = $this->repository();

        self::assertSame(3, $repository->countActiveByOwner($owner, new \DateTimeImmutable()));
        self::assertSame(5, $repository->countByOwner($owner));
        self::assertCount(5, $repository->listByOwner($owner, 0, 30));
        self::assertCount(2, $repository->listByOwner($owner, 0, 2), 'pagination');
    }

    public function testFindByIdAndOwnerHidesOtherUsersKeys(): void
    {
        $owner = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $key = ApiKeyFactory::createOne(['owner' => $owner]);
        $repository = $this->repository();

        self::assertInstanceOf(ApiKey::class, $repository->findByIdAndOwner($key->getId(), $owner));
        self::assertNull($repository->findByIdAndOwner($key->getId(), $stranger));
        self::assertNull($repository->findByIdAndOwner(Uuid::v7(), $owner));
    }

    public function testDeletingTheUserRemovesTheKeys(): void
    {
        $owner = UserFactory::createOne();
        ApiKeyFactory::createMany(2, ['owner' => $owner]);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => $owner->getId()->toRfc4122()]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM api_keys WHERE user_id = :id', ['id' => $owner->getId()->toRfc4122()]));
    }

    public function testMigrationRoundTrip(): void
    {
        // Two console processes, not one: doctrine/migrations freezes a migration
        // object after it ran once in a process, so down+up in-process would fail
        // on the second execution. DDL is transactional in PostgreSQL; each process
        // commits its own step, and the table ends up recreated.
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $version = 'DoctrineMigrations\\Version20260912134933';

        $this->console(['doctrine:migrations:execute', $version, '--down', '--no-interaction']);
        self::assertSame(0, (int) $connection->fetchOne("SELECT count(*) FROM information_schema.tables WHERE table_name = 'api_keys'"), 'down drops the table');

        $this->console(['doctrine:migrations:execute', $version, '--up', '--no-interaction']);
        self::assertSame(1, (int) $connection->fetchOne("SELECT count(*) FROM information_schema.tables WHERE table_name = 'api_keys'"), 'up recreates it');
        self::assertSame(1, (int) $connection->fetchOne("SELECT count(*) FROM pg_indexes WHERE tablename = 'api_keys' AND indexdef LIKE 'CREATE UNIQUE INDEX%(key_hash)'"), 'the hash is unique');
    }

    /**
     * @param list<string> $arguments
     */
    private function console(array $arguments): void
    {
        $process = new Process(['php', 'bin/console', ...$arguments, '--env=test'], \dirname(__DIR__, 3));
        $process->mustRun();
    }

    private function repository(): ApiKeyRepositoryInterface
    {
        // Constructed directly: until the token handler and the key API inject the
        // interface (tasks 2.1, 3.1) the unused alias is removed from the compiled
        // container, and this test is about the class against PostgreSQL anyway.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return new DoctrineApiKeyRepository($em);
    }

    private function lastUsedAt(Uuid $id): ?\DateTimeImmutable
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $value = $connection->fetchOne('SELECT last_used_at FROM api_keys WHERE id = :id', ['id' => $id->toRfc4122()]);

        return null === $value || false === $value ? null : new \DateTimeImmutable((string) $value);
    }
}
