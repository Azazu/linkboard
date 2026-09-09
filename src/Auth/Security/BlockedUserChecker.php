<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Auth\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Runs on every *authentication*: a bearer JWT on the api firewall and the
 * form login on the web firewall (specification FR-AUTH-6). It does NOT run
 * when an existing web session is refreshed — Symfony's ContextListener
 * bypasses user checkers — so UserProvider::refreshUser() enforces the same
 * policy for live sessions. The API turns this exception into a 403 problem
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
