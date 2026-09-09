<?php

declare(strict_types=1);

namespace App\Link\Security;

use App\Auth\Entity\User;
use App\Link\Api\LinkResource;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Owner or admin may view, edit and delete a link (specification §1).
 * Votes on the LinkResource DTO (what API Platform passes as `object`); the
 * web UI change reuses the same attributes.
 *
 * @extends Voter<string, LinkResource>
 */
final class LinkVoter extends Voter
{
    public const string VIEW = 'LINK_VIEW';
    public const string EDIT = 'LINK_EDIT';
    public const string DELETE = 'LINK_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true) && $subject instanceof LinkResource;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $user->isAdmin() || (string) $user->getId() === $subject->ownerId;
    }
}
