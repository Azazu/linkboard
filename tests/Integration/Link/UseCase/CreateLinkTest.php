<?php

declare(strict_types=1);

namespace App\Tests\Integration\Link\UseCase;

use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Link\UseCase\CreateLink;
use App\Link\UseCase\NewLink;
use App\Link\UseCase\SlugTaken;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The use case both the API and the UI create links through. What matters here
 * is the guard neither caller's validation can see: the unique index deciding
 * a slug the caller chose, which is what a race comes down to.
 */
#[CoversClass(CreateLink::class)]
final class CreateLinkTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAClientSlugThatTheIndexRefusesRaisesSlugTaken(): void
    {
        self::bootKernel();
        $owner = UserFactory::createOne();
        LinkFactory::createOne(['owner' => $owner, 'slug' => 'contested']);

        $this->expectException(SlugTaken::class);
        $this->expectExceptionMessage('This slug is already taken.');

        ($this->createLink())($owner, new NewLink(targetUrl: 'https://example.com/second', slug: 'contested'));
    }

    public function testAGeneratedSlugIsRetriedRatherThanRefused(): void
    {
        self::bootKernel();
        $owner = UserFactory::createOne();

        $link = ($this->createLink())($owner, new NewLink(targetUrl: 'https://example.com/generated'));

        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{7}$/', $link->getSlug());
        self::assertSame('https://example.com/generated', $link->getTargetUrl());
    }

    public function testEveryFieldOfTheInputIsStored(): void
    {
        self::bootKernel();
        $owner = UserFactory::createOne();
        $expiry = new \DateTimeImmutable('+1 year');

        $link = ($this->createLink())($owner, new NewLink(
            targetUrl: 'https://example.com/full',
            slug: 'full-house',
            utm: ['utm_source' => 'newsletter'],
            expiresAt: $expiry,
            maxClicks: 12,
            rules: ['version' => 1, 'rules' => [['match' => ['device' => ['tablet']], 'target' => 'https://example.com/t']]],
        ));

        $stored = $this->links()->findBySlug('full-house');
        self::assertInstanceOf(Link::class, $stored);
        self::assertSame($link->getId()->toRfc4122(), $stored->getId()->toRfc4122());
        self::assertSame(['utm_source' => 'newsletter'], $stored->getUtm());
        self::assertSame($expiry->getTimestamp(), $stored->getExpiresAt()?->getTimestamp());
        self::assertSame(12, $stored->getMaxClicks());
        self::assertEquals(['version' => 1, 'rules' => [['match' => ['device' => ['tablet']], 'target' => 'https://example.com/t']]], $stored->getRules());
    }

    private function createLink(): CreateLink
    {
        $service = self::getContainer()->get(CreateLink::class);
        self::assertInstanceOf(CreateLink::class, $service);

        return $service;
    }

    private function links(): LinkRepositoryInterface
    {
        $repository = self::getContainer()->get(LinkRepositoryInterface::class);
        self::assertInstanceOf(LinkRepositoryInterface::class, $repository);

        return $repository;
    }
}
