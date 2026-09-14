<?php

declare(strict_types=1);

namespace App\Tests\Web\Admin;

use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\UserFactory;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Spec web-ui — "The accounts page lists every account and blocks or unblocks
 * one", the listing half: what each row states, and that paging reaches every
 * account rather than truncating at a limit nothing tells the reader about.
 */
#[CoversNothing]
final class UserListTest extends WebPageTestCase
{
    public function testEachRowStatesTheAccountsAddressRolesStateAndRegistration(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $blocked = $this->user('bea@example.com');

        $this->signIn($client, 'root@example.com');
        $client->request('GET', '/admin/users/'.$blocked->getId().'/block');
        $client->submitForm('Block bea@example.com');
        $crawler = $client->followRedirect();

        $rows = $crawler->filter('tbody tr')->each(
            static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => trim($cell->text())),
        );
        $byEmail = array_column($rows, null, 0);
        self::assertSame('blocked', $byEmail['bea@example.com'][2]);
        self::assertSame('ROLE_USER', $byEmail['bea@example.com'][1]);
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], explode(', ', $byEmail['root@example.com'][1]));
        self::assertSame('active', $byEmail['root@example.com'][2]);
        self::assertSame($blocked->getCreatedAt()->format('Y-m-d'), $byEmail['bea@example.com'][3]);
    }

    public function testEveryAccountIsReachableThroughThePages(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        UserFactory::createMany(31);
        $oldest = $this->reload('root@example.com');

        $this->signIn($client, 'root@example.com');
        $first = $client->request('GET', '/admin/users');
        self::assertCount(30, $first->filter('tbody tr'));
        self::assertStringNotContainsString('root@example.com', $first->filter('tbody')->text());

        $second = $client->click($first->selectLink('Next')->link());
        self::assertCount(2, $second->filter('tbody tr'));
        self::assertStringContainsString($oldest->getEmail(), $second->filter('tbody')->text(), 'the oldest account is reachable');
    }

    private function reload(string $email): User
    {
        $users = self::getContainer()->get(UserRepositoryInterface::class);
        self::assertInstanceOf(UserRepositoryInterface::class, $users);
        $user = $users->findByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
