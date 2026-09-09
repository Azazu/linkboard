<?php

declare(strict_types=1);

namespace App\Tests\Api\Auth;

use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Specs: user-accounts (self-registration), api-error-format (422 violations).
 */
#[CoversNothing]
final class RegistrationTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testSuccessfulRegistrationReturns201WithoutPasswordMaterial(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/v1/auth/register', ['email' => 'ann@example.com', 'password' => 'correct-horse-battery']);

        self::assertResponseStatusCodeSame(201);
        $body = (string) $client->getResponse()->getContent();
        $user = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($user);
        self::assertSame('ann@example.com', $user['email']);
        self::assertSame(['ROLE_USER'], $user['roles']);
        self::assertArrayHasKey('id', $user);
        self::assertArrayHasKey('createdAt', $user);
        self::assertStringNotContainsString('password', $body);

        $stored = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail('ann@example.com');
        self::assertNotNull($stored);
        $hasher = self::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher($stored);
        self::assertTrue($hasher->verify($stored->getPassword(), 'correct-horse-battery'));
        self::assertNotSame('correct-horse-battery', $stored->getPassword());
    }

    public function testDuplicateEmailDifferingOnlyByCaseIs422(): void
    {
        // the client must be created before any factory boots the kernel
        $client = self::createClient();
        UserFactory::createOne(['email' => 'Ann@Example.com']);
        $client->jsonRequest('POST', '/api/v1/auth/register', ['email' => 'ann@example.com', 'password' => 'correct-horse-battery']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['email'], $this->violationPaths($client->getResponse()->getContent()));
    }

    public function testPasswordShorterThanTwelveIs422AndCreatesNothing(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/v1/auth/register', ['email' => 'ann@example.com', 'password' => '12345678901']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['password'], $this->violationPaths($client->getResponse()->getContent()));
        self::assertNull(self::getContainer()->get(UserRepositoryInterface::class)->findByEmail('ann@example.com'));
    }

    public function testInvalidPayloadCarriesOneViolationPerField(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/v1/auth/register', ['email' => 'not-an-email', 'password' => 'short']);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json; charset=utf-8');
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(422, $problem['status']);
        self::assertIsArray($problem['violations']);
        $byPath = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertNotSame('', $violation['message']);
            $byPath[$violation['propertyPath']] = $violation['message'];
        }
        self::assertSame(['email', 'password'], array_keys($byPath));
    }

    /**
     * @return list<string>
     */
    private function violationPaths(string|false $body): array
    {
        self::assertIsString($body);
        $problem = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertIsArray($problem['violations']);
        $paths = array_values(array_unique(array_map(static fn (array $v): string => (string) $v['propertyPath'], $problem['violations'])));
        sort($paths);

        return $paths;
    }
}
