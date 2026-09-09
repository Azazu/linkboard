<?php

declare(strict_types=1);

namespace App\Tests\Integration\Link;

use App\Link\Entity\Link;
use App\Link\LinkListQuery;
use App\Link\LinkRepositoryInterface;
use App\Link\Repository\DoctrineLinkRepository;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(DoctrineLinkRepository::class)]
#[CoversClass(LinkListQuery::class)]
final class DoctrineLinkRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testSlugsDifferingOnlyByCaseAreDifferentLinks(): void
    {
        $owner = UserFactory::createOne();
        LinkFactory::createOne(['owner' => $owner, 'slug' => 'Sale']);
        LinkFactory::createOne(['owner' => $owner, 'slug' => 'sale']);
        $repository = self::getContainer()->get(LinkRepositoryInterface::class);

        self::assertTrue($repository->slugExists('Sale'));
        self::assertTrue($repository->slugExists('sale'));
        self::assertFalse($repository->slugExists('SALE'));
        self::assertSame('Sale', $repository->findBySlug('Sale')?->getSlug());
    }

    public function testExactDuplicateSlugIsRejectedByTheUniqueIndex(): void
    {
        $owner = UserFactory::createOne();
        LinkFactory::createOne(['owner' => $owner, 'slug' => 'sale']);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        self::getContainer()->get(LinkRepositoryInterface::class)->add(new Link($owner, 'sale', 'https://example.com/other', new \DateTimeImmutable()));

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function testZeroClickLimitIsRejectedByTheCheckConstraint(): void
    {
        $owner = UserFactory::createOne();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $link = new Link($owner, 'limited', 'https://example.com/', new \DateTimeImmutable());
        $em->persist($link);
        $em->flush();

        // the entity guards the value; the database is the authority — bypass the entity to prove it
        try {
            $em->getConnection()->executeStatement('UPDATE links SET max_clicks = 0 WHERE slug = ?', ['limited']);
            self::fail('the check constraint must reject a zero click limit');
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState(), 'SQLSTATE 23514 = check_violation');
        }
    }

    public function testOwnerPagesFilterOrderAndCount(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        LinkFactory::createOne(['owner' => $a, 'slug' => 'promo-1', 'now' => new \DateTimeImmutable('2026-01-01T00:00:00Z')]);
        LinkFactory::new()->inactive()->create(['owner' => $a, 'slug' => 'promo-2', 'now' => new \DateTimeImmutable('2026-01-02T00:00:00Z')]);
        LinkFactory::createOne(['owner' => $a, 'slug' => 'other', 'now' => new \DateTimeImmutable('2026-01-03T00:00:00Z')]);
        LinkFactory::createOne(['owner' => $b, 'slug' => 'promo-9']);
        $repository = self::getContainer()->get(LinkRepositoryInterface::class);

        $all = new LinkListQuery();
        self::assertSame(3, $repository->countForOwner($a->getId(), $all));
        self::assertSame(['other', 'promo-2', 'promo-1'], array_map(static fn (Link $l): string => $l->getSlug(), $repository->findPageForOwner($a->getId(), $all, 0, 10)));
        self::assertSame(['promo-1', 'promo-2'], array_map(static fn (Link $l): string => $l->getSlug(), $repository->findPageForOwner($a->getId(), new LinkListQuery(orderField: 'createdAt', direction: 'asc'), 0, 2)));

        $inactivePromo = new LinkListQuery(isActive: false, slugContains: 'promo');
        self::assertSame(['promo-2'], array_map(static fn (Link $l): string => $l->getSlug(), $repository->findPageForOwner($a->getId(), $inactivePromo, 0, 10)));
        self::assertSame(1, $repository->countForOwner($a->getId(), $inactivePromo));

        // LIKE wildcards in the filter are literal
        self::assertSame(0, $repository->countForOwner($a->getId(), new LinkListQuery(slugContains: '%')));
        // admin listing sees both owners
        self::assertSame(4, $repository->count($all));
        self::assertSame(3, $repository->count(new LinkListQuery(slugContains: 'promo')));
    }

    public function testUnsupportedOrderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LinkListQuery(orderField: 'slug');
    }
}
