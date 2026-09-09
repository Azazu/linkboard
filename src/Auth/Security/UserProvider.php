<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads users by email through the repository so the lookup is
 * case-insensitive (lower(email) index) for every firewall.
 *
 * @implements UserProviderInterface<User>
 */
final readonly class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return $this->users->findByEmail($identifier)
            ?? throw new UserNotFoundException(\sprintf('No account for "%s".', $identifier));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Unsupported user class %s.', $user::class));
        }

        // Re-read on every request so blocking and role changes apply immediately.
        $fresh = $this->users->findById($user->getId())
            ?? throw new UserNotFoundException('Account no longer exists.');

        // The session context listener refreshes users without running the
        // user checker, so a block would not end an existing web session; the
        // AccountStatusException from here reaches the firewall's exception
        // listener, which drops the token and redirects to /login with the error.
        if ($fresh->isBlocked()) {
            throw new CustomUserMessageAccountStatusException(BlockedUserChecker::MESSAGE);
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if ($user instanceof User) {
            $user->changePasswordHash($newHashedPassword, new \DateTimeImmutable());
        }
    }
}
