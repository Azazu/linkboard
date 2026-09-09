<?php

declare(strict_types=1);

namespace App\Tests\Web\Security;

use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec: user-accounts — "Successful web registration" and the shared constraints.
 */
#[CoversNothing]
final class RegistrationTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testValidFormCreatesTheAccountAndRedirectsToLogin(): void
    {
        $client = self::createClient();

        $client->request('GET', '/register');
        $client->submitForm('Register', [
            'registration_form[email]' => 'ann@example.com',
            'registration_form[password]' => 'correct-horse-battery',
        ]);

        self::assertResponseRedirects('/login');
        self::assertNotNull(self::getContainer()->get(UserRepositoryInterface::class)->findByEmail('ann@example.com'));
    }

    public function testShortPasswordAndDuplicateEmailAreRejected(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'Ann@Example.com']);

        $client->request('GET', '/register');
        $client->submitForm('Register', [
            'registration_form[email]' => 'ann@example.com',
            'registration_form[password]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'already exists');
        self::assertSelectorTextContains('body', '12 characters');
    }
}
