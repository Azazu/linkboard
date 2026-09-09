<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Link\Entity\Link;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Link>
 */
final class LinkFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Link::class;
    }

    protected function defaults(): array
    {
        return [
            'owner' => UserFactory::new(),
            'slug' => self::faker()->unique()->regexify('[A-Za-z0-9]{7}'),
            'targetUrl' => 'https://example.com/'.self::faker()->unique()->slug(),
            'now' => new \DateTimeImmutable(),
        ];
    }

    public function inactive(): static
    {
        return $this->afterInstantiate(static function (Link $link): void {
            $link->deactivate(new \DateTimeImmutable());
        });
    }
}
