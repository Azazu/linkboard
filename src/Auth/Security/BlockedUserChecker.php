<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Auth\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Runs on every authentication — a JWT on the api firewall, the session
 * refresh on the web firewall — so a block takes effect on the next request
 * (specification FR-AUTH-6). The API turns this exception into a 403 problem
 * with detail "blocked" (JwtProblemDetailsSubscriber).
 */
final class BlockedUserChecker implements UserCheckerInterface
{
    public const string MESSAGE = 'blocked';

    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isBlocked()) {
            throw new CustomUserMessageAccountStatusException(self::MESSAGE);
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
