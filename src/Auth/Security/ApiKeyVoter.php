<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Auth\Entity\ApiKey;
use App\Auth\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only the owner sees and revokes a key — no admin override (permission
 * matrix "own / own"). Votes on the entity; the API scopes its queries by
 * owner as well, the web UI change calls this voter directly.
 *
 * @extends Voter<string, ApiKey>
 */
final class ApiKeyVoter extends Voter
{
    public const string VIEW = 'API_KEY_VIEW';
    public const string REVOKE = 'API_KEY_REVOKE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::REVOKE], true) && $subject instanceof ApiKey;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->getId()->equals($subject->getOwner()->getId());
    }
}
