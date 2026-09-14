<?php

declare(strict_types=1);

namespace App\Tests\Web\Admin;

use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\LinkFactory;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Spec web-ui — "The administrative pages and their actions are reachable only
 * by an administrator".
 *
 * The account-changing requests are covered here as well as the pages, and the
 * two guards are kept apart: a submission carrying a token the ordinary user's
 * own session would accept must still be refused, by the role. A passing
 * forgery check must never stand in for a missing role check.
 */
#[CoversNothing]
final class AdminAccessTest extends WebPageTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'accounts' => ['/admin/users'];
        yield 'links' => ['/admin/links'];
        yield 'statistics' => ['/admin/stats'];
    }

    #[DataProvider('pages')]
    public function testAnAdministratorReachesEveryPage(string $path): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);

        $this->signIn($client, 'root@example.com');
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('pages')]
    public function testAnOrdinaryUserIsRefusedAndSeesNoData(string $path): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'secret-slug']);

        $this->signIn($client, 'ann@example.com');
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('secret-slug', (string) $client->getResponse()->getContent());
    }

    #[DataProvider('pages')]
    public function testAGuestIsSentToTheLoginPage(string $path): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertResponseRedirects('/login');
    }

    public function testAShortLinkWhoseSlugBeginsWithAdminStillRedirects(): void
    {
        // the access-control pattern is an unanchored regular expression: without
        // its segment boundary this public redirect would go to the login page
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'admin-sale', 'targetUrl' => 'https://example.com/sale']);

        $client->request('GET', '/admin-sale');

        self::assertResponseStatusCodeSame(302);
        self::assertSame('https://example.com/sale', $client->getResponse()->headers->get('Location'));
    }

    public function testTheNavigationNamesTheAdministrativePagesOnlyToAnAdministrator(): void
    {
        $client = self::createClient();
        $this->user('root@example.com', admin: true);
        $this->user('ann@example.com');

        $this->signIn($client, 'root@example.com');
        $crawler = $client->request('GET', '/dashboard');
        self::assertCount(1, $crawler->filter('header a[href="/admin/users"]'));

        $client->request('GET', '/logout');
        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/dashboard');
        self::assertCount(0, $crawler->filter('header a[href="/admin/users"]'));
    }

    public function testAnOrdinaryUserCannotBlockAnAccountEvenWithAValidToken(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $target = $this->user('bea@example.com');

        $this->signIn($client, 'ann@example.com');
        foreach (['block', 'unblock'] as $action) {
            $path = '/admin/users/'.$target->getId().'/'.$action;

            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, "the $action confirmation page");

            $client->request('POST', $path, ['_token' => $this->tokenOfThisSession($client)]);
            self::assertResponseStatusCodeSame(403, "the $action submission with a token this session would accept");
        }

        self::assertFalse($this->reload('bea@example.com')->isBlocked(), 'nothing changed');
    }

    public function testAGuestCannotBlockAnAccount(): void
    {
        $client = self::createClient();
        $target = $this->user('bea@example.com');

        $client->request('POST', '/admin/users/'.$target->getId().'/block', ['_token' => 'anything']);

        self::assertResponseRedirects('/login');
        self::assertFalse($this->reload('bea@example.com')->isBlocked());
    }

    /** The very token this session's own forms carry, so only the role can refuse. */
    private function tokenOfThisSession(KernelBrowser $client): string
    {
        $client->request('GET', '/api-keys');
        $token = $client->getCrawler()->filter('input[name="api_key[_token]"]')->attr('value');
        self::assertIsString($token);

        return $token;
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
