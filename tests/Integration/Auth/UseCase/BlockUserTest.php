<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth\UseCase;

use App\Auth\Entity\User;
use App\Auth\UseCase\BlockUser;
use App\Auth\UseCase\CannotBlockOwnAccount;
use App\Auth\UseCase\UnblockUser;
use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\UserFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The one place an account is blocked (design decision 5 of
 * add-web-admin-and-stats). The API's processor and the administrative page
 * are adapters over this, so the guard and the audit line are asserted here
 * once rather than at each entry point.
 */
#[CoversClass(BlockUser::class)]
#[CoversClass(UnblockUser::class)]
final class BlockUserTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testBlockingAndUnblockingAnAccount(): void
    {
        self::bootKernel();
        $admin = UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $target = UserFactory::createOne(['email' => 'ann@example.com']);
        $this->signIn($admin);
        $log = $this->auditLog();

        $this->blockUser()($target);

        self::assertTrue($this->reload('ann@example.com')->isBlocked());
        $record = self::records($log, 'user.block');
        self::assertCount(1, $record);
        self::assertSame((string) $admin->getId(), $record[0]->context['actor_id']);
        self::assertSame((string) $target->getId(), $record[0]->context['target_id']);

        $this->unblockUser()($target);

        self::assertFalse($this->reload('ann@example.com')->isBlocked());
        self::assertCount(1, self::records($log, 'user.unblock'));
    }

    public function testBlockingAnAlreadyBlockedAccountChangesNothing(): void
    {
        self::bootKernel();
        $admin = UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $target = UserFactory::createOne(['email' => 'ann@example.com']);
        $this->signIn($admin);

        $this->blockUser()($target);
        $blockedAt = $this->reload('ann@example.com')->isBlocked();
        $this->blockUser()($target);

        self::assertTrue($blockedAt);
        self::assertTrue($this->reload('ann@example.com')->isBlocked(), 'the second block is a no-op, not a failure');
    }

    public function testAnAdministratorCannotBlockTheirOwnAccount(): void
    {
        self::bootKernel();
        $admin = UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $this->signIn($admin);
        $log = $this->auditLog();

        try {
            $this->blockUser()($admin);
            self::fail('blocking one\'s own account must be refused');
        } catch (CannotBlockOwnAccount $e) {
            self::assertStringContainsString('own account', $e->getMessage());
        }

        self::assertFalse($this->reload('admin@example.com')->isBlocked(), 'nothing changed');
        self::assertSame([], self::records($log, 'user.block'), 'a refused action is not audited');
    }

    private function signIn(User $user): void
    {
        $storage = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        self::assertInstanceOf(Security::class, self::getContainer()->get(Security::class));
    }

    private function auditLog(): TestHandler
    {
        $handler = new TestHandler();
        $logger = self::getContainer()->get('monolog.logger.audit');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($handler);

        return $handler;
    }

    /**
     * @return list<\Monolog\LogRecord>
     */
    private static function records(TestHandler $log, string $action): array
    {
        return array_values(array_filter($log->getRecords(), static fn ($r): bool => ($r->context['action'] ?? null) === $action));
    }

    private function reload(string $email): User
    {
        $users = self::getContainer()->get(UserRepositoryInterface::class);
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $user = $users->findByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function blockUser(): BlockUser
    {
        $useCase = self::getContainer()->get(BlockUser::class);
        self::assertInstanceOf(BlockUser::class, $useCase);

        return $useCase;
    }

    private function unblockUser(): UnblockUser
    {
        $useCase = self::getContainer()->get(UnblockUser::class);
        self::assertInstanceOf(UnblockUser::class, $useCase);

        return $useCase;
    }
}
