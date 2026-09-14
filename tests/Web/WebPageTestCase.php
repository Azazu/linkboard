<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Entity\User;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Signing in the way a person does — through the form — because that is what
 * the pages' authorization actually runs on. The suite has no loginUser()
 * shortcut anywhere, and this keeps it that way.
 */
abstract class WebPageTestCase extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    protected function signIn(KernelBrowser $client, string $email): void
    {
        // the GET first: it seeds the stateless CSRF cookie the login form needs
        $client->request('GET', '/login');
        $client->submitForm('Log in', ['email' => $email, 'password' => UserFactory::PASSWORD]);
        self::assertResponseRedirects();
        $client->followRedirect();
    }

    protected function user(string $email, bool $admin = false): User
    {
        $factory = UserFactory::new(['email' => $email]);

        return $admin ? $factory->admin()->create() : $factory->create();
    }
}
