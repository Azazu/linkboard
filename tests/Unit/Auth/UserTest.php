<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    public function testRolesAlwaysContainRoleUserExactlyOnce(): void
    {
        $user = new User('ann@example.com', 'hash', new \DateTimeImmutable('2026-09-09T00:00:00Z'));

        self::assertSame([User::ROLE_USER], $user->getRoles());
        self::assertFalse($user->isAdmin());
    }

    public function testPromoteAndDemoteAreIdempotent(): void
    {
        $now = new \DateTimeImmutable('2026-09-09T00:00:00Z');
        $user = new User('ann@example.com', 'hash', $now);

        $user->promoteToAdmin($now);
        $user->promoteToAdmin($now);
        self::assertSame([User::ROLE_ADMIN, User::ROLE_USER], $user->getRoles());
        self::assertTrue($user->isAdmin());

        $user->demoteFromAdmin($now);
        $user->demoteFromAdmin($now);
        self::assertSame([User::ROLE_USER], $user->getRoles());
    }

    public function testBlockAndUnblockTouchUpdatedAt(): void
    {
        $created = new \DateTimeImmutable('2026-09-09T00:00:00Z');
        $later = new \DateTimeImmutable('2026-09-09T01:00:00Z');
        $user = new User('ann@example.com', 'hash', $created);

        $user->block($later);
        self::assertTrue($user->isBlocked());
        self::assertSame($later, $user->getUpdatedAt());
        self::assertSame($created, $user->getCreatedAt());

        $user->unblock($later);
        self::assertFalse($user->isBlocked());
    }

    public function testEmptyEmailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new User('', 'hash', new \DateTimeImmutable());
    }
}
