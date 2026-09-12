<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Auth\Entity\ApiKey;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Test API keys. `plaintext` is not an entity field: the factory derives the
 * stored hash and prefix from it, so a test that needs to *use* the key passes
 * its own plaintext (e.g. ApiKeyFactory::plaintext()) and presents it later.
 *
 * @extends PersistentObjectFactory<ApiKey>
 */
final class ApiKeyFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ApiKey::class;
    }

    /** A well-formed plaintext key (lb_ + 40 base62 characters) for tests. */
    public static function plaintext(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $body = '';
        for ($i = 0; $i < 40; ++$i) {
            $body .= $alphabet[random_int(0, 61)];
        }

        return 'lb_'.$body;
    }

    /**
     * @return array{keyHash: string, prefix: string}
     */
    public static function stored(string $plaintext): array
    {
        return ['keyHash' => hash('sha256', $plaintext), 'prefix' => substr($plaintext, 0, 8)];
    }

    protected function defaults(): array
    {
        return [
            'owner' => UserFactory::new(),
            'name' => self::faker()->words(2, true),
            'expiresAt' => null,
            'now' => new \DateTimeImmutable(),
            ...self::stored(self::plaintext()),
        ];
    }

    /** Stores the hash and prefix of the given plaintext. */
    public function forPlaintext(string $plaintext): static
    {
        return $this->with(self::stored($plaintext));
    }

    public function revoked(): static
    {
        return $this->afterInstantiate(static function (ApiKey $key): void {
            $key->revoke(new \DateTimeImmutable('-1 hour'));
        });
    }

    public function expired(): static
    {
        return $this->with(['expiresAt' => new \DateTimeImmutable('-1 day')]);
    }
}
