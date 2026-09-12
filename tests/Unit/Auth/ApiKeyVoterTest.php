<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Entity\ApiKey;
use App\Auth\Entity\User;
use App\Auth\Security\ApiKeyVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Permission matrix "Create and revoke API keys: own / own" — no admin override. */
#[CoversClass(ApiKeyVoter::class)]
final class ApiKeyVoterTest extends TestCase
{
    public function testOwnerYesStrangerNoAdminStrangerNo(): void
    {
        $now = new \DateTimeImmutable();
        $owner = new User('owner@example.com', 'h', $now);
        $stranger = new User('stranger@example.com', 'h', $now);
        $admin = new User('admin@example.com', 'h', $now);
        $admin->promoteToAdmin($now);
        $key = new ApiKey($owner, 'ci', str_repeat('a', 64), 'lb_aaaaa', null, $now);
        $voter = new ApiKeyVoter();

        foreach ([ApiKeyVoter::VIEW, ApiKeyVoter::REVOKE] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new UsernamePasswordToken($owner, 'api'), $key, [$attribute]), "$attribute owner");
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken($stranger, 'api'), $key, [$attribute]), "$attribute stranger");
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken($admin, 'api'), $key, [$attribute]), "$attribute admin who is not the owner");
        }
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new UsernamePasswordToken($owner, 'api'), new \stdClass(), [ApiKeyVoter::VIEW]), 'other subjects');
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new UsernamePasswordToken($owner, 'api'), $key, ['LINK_VIEW']), 'other attributes');
    }
}
