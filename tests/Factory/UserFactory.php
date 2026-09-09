<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Auth\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Test users. The password hash is the test hasher's output for
 * self::PASSWORD (cost 4 in the test environment), computed once per process.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public const string PASSWORD = 'correct-horse-battery-staple';

    public static function class(): string
    {
        return User::class;
    }

    public static function passwordHash(): string
    {
        // bcrypt cost 4: fast, and what the test security config uses (security.yaml when@test)
        static $hash = null;

        return $hash ??= password_hash(self::PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]);
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'passwordHash' => self::passwordHash(),
            'now' => new \DateTimeImmutable(),
        ];
    }

    public function admin(): static
    {
        return $this->afterInstantiate(static function (User $user): void {
            $user->promoteToAdmin(new \DateTimeImmutable());
        });
    }

    public function blocked(): static
    {
        return $this->afterInstantiate(static function (User $user): void {
            $user->block(new \DateTimeImmutable());
        });
    }
}
