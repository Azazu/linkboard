<?php

declare(strict_types=1);

namespace App\Tests\Api\Auth;

use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec: user-accounts — "Blocked accounts are refused everywhere" (API path).
 * Failing input for the blocking guard: a still-valid JWT replayed after the block.
 */
#[CoversNothing]
final class BlockedUserTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testValidJwtIsRefusedWith403BlockedOnceTheAccountIsBlocked(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'ann@example.com']);
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'ann@example.com', 'password' => UserFactory::PASSWORD]);
        $token = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['token'];
        self::assertIsString($token);

        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);

        $repository = self::getContainer()->get(UserRepositoryInterface::class);
        $user = $repository->findByEmail('ann@example.com');
        self::assertNotNull($user);
        $user->block(new \DateTimeImmutable());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('blocked', $problem['detail']);
    }

    public function testBlockedUserCannotObtainAToken(): void
    {
        $client = self::createClient();
        UserFactory::new()->blocked()->create(['email' => 'ann@example.com']);

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'ann@example.com', 'password' => UserFactory::PASSWORD]);

        self::assertResponseStatusCodeSame(403);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('blocked', $problem['detail']);
    }
}
