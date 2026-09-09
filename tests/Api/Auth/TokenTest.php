<?php

declare(strict_types=1);

namespace App\Tests\Api\Auth;

use App\Tests\Factory\UserFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec: authentication — "JWT for the API".
 */
#[CoversNothing]
final class TokenTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testTokenIsIssuedAndOpensMe(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['email' => 'ann@example.com']);

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'ann@example.com', 'password' => UserFactory::PASSWORD]);

        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['token']);
        self::assertIsString($body['expiresAt']);
        $expiresAt = new \DateTimeImmutable($body['expiresAt']);
        $expectedExpiry = (new \DateTimeImmutable())->modify('+3600 seconds');
        self::assertEqualsWithDelta($expectedExpiry->getTimestamp(), $expiresAt->getTimestamp(), 30, 'expiresAt must be about one hour ahead');

        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$body['token'], 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        $me = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($me);
        self::assertSame((string) $user->getId(), $me['id']);
        self::assertSame('ann@example.com', $me['email']);
        self::assertSame(['ROLE_USER'], $me['roles']);
        self::assertArrayHasKey('createdAt', $me);
        self::assertArrayNotHasKey('password', $me);
    }

    public function testWrongPasswordIs401ProblemDetailsWithoutEmailDisclosure(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'ann@example.com']);

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'ann@example.com', 'password' => 'definitely-not-the-password']);
        $this->assertProblem($client, 401);

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'nobody@example.com', 'password' => 'definitely-not-the-password']);
        $unknown = $this->assertProblem($client, 401);
        self::assertStringNotContainsString('nobody', $unknown['detail']);
    }

    public function testMissingTokenIs401ProblemDetails(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/me', server: ['HTTP_ACCEPT' => 'application/json']);

        $this->assertProblem($client, 401);
    }

    public function testMalformedTokenIs401ProblemDetails(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer not.a.jwt', 'HTTP_ACCEPT' => 'application/json']);

        $this->assertProblem($client, 401);
    }

    public function testExpiredTokenIs401ProblemDetails(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'ann@example.com']);
        $encoder = self::getContainer()->get(JWTEncoderInterface::class);
        $expired = $encoder->encode(['username' => 'ann@example.com', 'roles' => ['ROLE_USER'], 'exp' => time() - 60, 'iat' => time() - 3660]);

        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$expired, 'HTTP_ACCEPT' => 'application/json']);

        $problem = $this->assertProblem($client, 401);
        self::assertStringContainsStringIgnoringCase('expired', $problem['detail']);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertProblem(KernelBrowser $client, int $status): array
    {
        self::assertResponseStatusCodeSame($status);
        $contentType = (string) $client->getResponse()->headers->get('Content-Type');
        self::assertStringStartsWith('application/problem+json', $contentType);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame($status, $problem['status']);
        self::assertIsString($problem['detail']);

        /** @var array<string, mixed> $problem */
        return $problem;
    }
}
