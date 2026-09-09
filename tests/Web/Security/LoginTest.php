<?php

declare(strict_types=1);

namespace App\Tests\Web\Security;

use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec: authentication — "Session login for the web"; user-accounts —
 * "Blocked accounts are refused everywhere" (web path).
 */
#[CoversNothing]
final class LoginTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testValidCredentialsCreateASessionAndRedirectHome(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'ann@example.com']);

        $client->request('GET', '/login');
        $client->submitForm('Log in', ['email' => 'ann@example.com', 'password' => UserFactory::PASSWORD]);

        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Signed in as ann@example.com');
    }

    public function testWrongPasswordShowsAGenericErrorWithoutRevealingTheEmail(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'ann@example.com']);

        $client->request('GET', '/login');
        $client->submitForm('Log in', ['email' => 'nobody@example.com', 'password' => 'wrong-password-here']);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role=alert]', 'Invalid credentials.');
        self::assertSelectorTextNotContains('[role=alert]', 'nobody');
        $client->request('GET', '/');
        self::assertSelectorTextNotContains('body', 'Signed in as');
    }

    public function testPostWithoutCsrfTokenIsRefused(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'ann@example.com']);

        // No prior GET, no token, no Referer/Origin: the stateless CSRF check fails.
        $client->request('POST', '/login', ['email' => 'ann@example.com', 'password' => UserFactory::PASSWORD]);

        self::assertResponseRedirects('/login');
        $client->request('GET', '/');
        self::assertSelectorTextNotContains('body', 'Signed in as');
    }

    public function testBlockedUserSeesTheBlockedMessage(): void
    {
        $client = self::createClient();
        UserFactory::new()->blocked()->create(['email' => 'ann@example.com']);

        $client->request('GET', '/login');
        $client->submitForm('Log in', ['email' => 'ann@example.com', 'password' => UserFactory::PASSWORD]);
        $client->followRedirect();

        self::assertSelectorTextContains('[role=alert]', 'This account is blocked.');
        $client->request('GET', '/');
        self::assertSelectorTextNotContains('body', 'Signed in as');
    }
}
