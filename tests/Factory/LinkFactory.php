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

    /** @param array<string, string> $utm */
    public function withUtm(array $utm): static
    {
        return $this->afterInstantiate(static function (Link $link) use ($utm): void {
            $link->replaceUtm($utm, new \DateTimeImmutable());
        });
    }

    public function expiring(\DateTimeImmutable $at): static
    {
        return $this->afterInstantiate(static function (Link $link) use ($at): void {
            $link->setExpiry($at, new \DateTimeImmutable());
        });
    }

    /** click_count is written by SQL in production (design decision 4); tests seed it through reflection before persist */
    public function limited(int $maxClicks, int $clickCount = 0): static
    {
        return $this->afterInstantiate(static function (Link $link) use ($maxClicks, $clickCount): void {
            $link->setClickLimit($maxClicks, new \DateTimeImmutable());
            if ($clickCount > 0) {
                new \ReflectionProperty(Link::class, 'clickCount')->setValue($link, $clickCount);
            }
        });
    }
}
