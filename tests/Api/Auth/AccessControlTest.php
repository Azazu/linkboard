<?php

declare(strict_types=1);

namespace App\Tests\Api\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec: authentication — "Public and protected surfaces".
 */
#[CoversNothing]
final class AccessControlTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function publicPaths(): iterable
    {
        yield 'openapi document' => ['/api/docs.json', []];
        yield 'swagger ui' => ['/api/docs', ['HTTP_ACCEPT' => 'text/html']];
        yield 'versioned base path' => ['/api/v1', ['HTTP_ACCEPT' => 'application/json']];
        yield 'health' => ['/health', []];
        yield 'login page' => ['/login', []];
        yield 'register page' => ['/register', []];
    }

    /**
     * @param array<string, string> $server
     */
    #[DataProvider('publicPaths')]
    public function testPublicSurfaceNeedsNoAuthentication(string $path, array $server): void
    {
        $client = self::createClient();
        $client->request('GET', $path, server: $server);

        self::assertResponseStatusCodeSame(200);
    }

    public function testProtectedApiOperationRefusesAnonymousWithProblemDetails(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/me', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }
}
