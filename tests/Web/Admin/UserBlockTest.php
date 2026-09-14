<?php

declare(strict_types=1);

namespace App\Tests\Web\Admin;

use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\UserFactory;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec web-ui — "The accounts page lists every account and blocks or unblocks
 * one". The action itself is the use case both entry points share; what is
 * asserted here is the page around it: the confirmation, the way back, the
 * forgery check, and that a refusal leaves everything as it was.
 */
#[CoversNothing]
final class UserBlockTest extends WebPageTestCase
{
    public function testNothingHappensBeforeTheConfirmation(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $target = $this->user('bea@example.com');

        $this->signIn($client, 'root@example.com');
        $crawler = $client->request('GET', '/admin/users/'.$target->getId().'/block');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('bea@example.com', $crawler->filter('body')->text());
        self::assertStringContainsString('cannot sign in', $crawler->filter('[role=alert]')->text());
        self::assertCount(1, $crawler->filter('a[href="/admin/users"]')->reduce(
            static fn ($node): bool => 'Cancel' === trim($node->text()),
        ), 'the way back is offered');
        self::assertFalse($this->reload('bea@example.com')->isBlocked(), 'arriving at the page changes nothing');

        $client->click($crawler->selectLink('Cancel')->link());
        self::assertFalse($this->reload('bea@example.com')->isBlocked(), 'the way back changes nothing');
    }

    public function testAConfirmedBlockBlocksAndThenUnblocks(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $target = $this->user('bea@example.com');

        $this->signIn($client, 'root@example.com');
        $client->request('GET', '/admin/users/'.$target->getId().'/block');
        $client->submitForm('Block bea@example.com');

        self::assertResponseRedirects('/admin/users', 303);
        self::assertTrue($this->reload('bea@example.com')->isBlocked());
        self::assertStringContainsString('was blocked', $client->followRedirect()->filter('[role=alert]')->text());

        $client->request('GET', '/admin/users/'.$target->getId().'/unblock');
        $client->submitForm('Unblock bea@example.com');

        self::assertResponseRedirects('/admin/users', 303);
        self::assertFalse($this->reload('bea@example.com')->isBlocked());
    }

    public function testABlockedAccountCannotSignIn(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $target = $this->user('bea@example.com');

        $this->signIn($client, 'root@example.com');
        $client->request('GET', '/admin/users/'.$target->getId().'/block');
        $client->submitForm('Block bea@example.com');
        $client->request('GET', '/logout');

        $client->request('GET', '/login');
        $client->submitForm('Log in', ['email' => 'bea@example.com', 'password' => UserFactory::PASSWORD]);
        $client->followRedirect();

        self::assertSame('/login', parse_url((string) $client->getRequest()->getUri(), \PHP_URL_PATH), 'a blocked account stays out');
    }

    public function testAnAdministratorCannotBlockTheirOwnAccount(): void
    {
        $client = self::createClient();
        $root = $this->user('root@example.com', admin: true);

        $this->signIn($client, 'root@example.com');
        $client->request('GET', '/admin/users/'.$root->getId().'/block');
        $client->submitForm('Block root@example.com');

        self::assertResponseRedirects('/admin/users', 303);
        self::assertStringContainsString('own account', $client->followRedirect()->filter('[role=alert]')->text());
        self::assertFalse($this->reload('root@example.com')->isBlocked());
        // still signed in: the page is served, not the login form
        $client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }

    public function testASubmissionWithoutAValidTokenChangesNothing(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $target = $this->user('bea@example.com');

        $this->signIn($client, 'root@example.com');
        $client->request('POST', '/admin/users/'.$target->getId().'/block', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload('bea@example.com')->isBlocked());
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
