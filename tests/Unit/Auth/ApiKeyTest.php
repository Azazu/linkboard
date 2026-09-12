<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Entity\ApiKey;
use App\Auth\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Spec api-keys: a key is active while neither revoked nor expired; revocation is idempotent. */
#[CoversClass(ApiKey::class)]
final class ApiKeyTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testActiveMatrix(): void
    {
        $now = new \DateTimeImmutable('2026-09-12T12:00:00Z');
        $user = new User('a@example.com', 'hash', $now);

        $fresh = new ApiKey($user, 'ci', self::HASH, 'lb_aaaaa', null, $now);
        $expiringLater = new ApiKey($user, 'ci', self::HASH, 'lb_aaaaa', $now->modify('+1 day'), $now);
        $expired = new ApiKey($user, 'ci', self::HASH, 'lb_aaaaa', $now->modify('-1 second'), $now);
        $revoked = new ApiKey($user, 'ci', self::HASH, 'lb_aaaaa', null, $now);
        $revoked->revoke($now);

        self::assertTrue($fresh->isActive($now));
        self::assertTrue($expiringLater->isActive($now));
        self::assertFalse($expiringLater->isActive($now->modify('+2 days')), 'expiry is evaluated at the given time');
        self::assertFalse($expired->isActive($now));
        self::assertFalse($revoked->isActive($now));
        self::assertNull($fresh->getLastUsedAt());
    }

    public function testRevokeTwiceKeepsTheFirstTimestamp(): void
    {
        $now = new \DateTimeImmutable('2026-09-12T12:00:00Z');
        $key = new ApiKey(new User('a@example.com', 'hash', $now), 'ci', self::HASH, 'lb_aaaaa', null, $now);

        $key->revoke($now->modify('+1 minute'));
        $key->revoke($now->modify('+2 minutes'));

        self::assertEquals($now->modify('+1 minute'), $key->getRevokedAt());
    }

    public function testConstructorGuards(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('a@example.com', 'hash', $now);

        $this->expectException(\InvalidArgumentException::class);
        new ApiKey($user, '   ', self::HASH, 'lb_aaaaa', null, $now);
    }

    public function testNameIsTrimmedAndBounded(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('a@example.com', 'hash', $now);

        self::assertSame('ci deploy', new ApiKey($user, '  ci deploy ', self::HASH, 'lb_aaaaa', null, $now)->getName());
        $this->expectException(\InvalidArgumentException::class);
        new ApiKey($user, str_repeat('x', 65), self::HASH, 'lb_aaaaa', null, $now);
    }

    public function testHashAndPrefixShape(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('a@example.com', 'hash', $now);

        $this->expectException(\InvalidArgumentException::class);
        new ApiKey($user, 'ci', 'not-a-hash', 'lb_aaaaa', null, $now);
    }
}
