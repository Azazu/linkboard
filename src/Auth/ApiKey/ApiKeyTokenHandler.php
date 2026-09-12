<?php

declare(strict_types=1);

namespace App\Auth\ApiKey;

use App\Auth\ApiKeyRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * FR-KEY-2 (design decision 4): the presented key is hashed and looked up by
 * its hash only — no plaintext is stored or compared. An unknown, revoked or
 * expired key is a BadCredentialsException (401 through the failure handler);
 * a hit marks last_used_at at most once a minute and returns the owner. The
 * key id travels as a badge attribute for the rate limiter. The firewall's
 * user checker then refuses a blocked owner.
 */
final readonly class ApiKeyTokenHandler implements AccessTokenHandlerInterface
{
    public const string BADGE_ATTRIBUTE = 'api_key_id';
    public const int LAST_USED_GRANULARITY_SECONDS = 60;

    public function __construct(
        private ApiKeyRepositoryInterface $keys,
        private ClockInterface $clock,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $now = $this->clock->now();
        $key = $this->keys->findActiveByHash(ApiKeyGenerator::hash($accessToken), $now)
            ?? throw new BadCredentialsException('Invalid API key.');

        $this->keys->touchLastUsed($key->getId(), $now, $now->modify(\sprintf('-%d seconds', self::LAST_USED_GRANULARITY_SECONDS)));
        $owner = $key->getOwner();

        return new UserBadge($owner->getUserIdentifier(), static fn () => $owner, [self::BADGE_ATTRIBUTE => $key->getId()->toRfc4122()]);
    }
}
