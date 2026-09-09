<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

abstract class LinkApiTestCase extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    protected function token(KernelBrowser $client, string $email): string
    {
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => $email, 'password' => UserFactory::PASSWORD]);
        $token = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(KernelBrowser $client, string $token, string $method, string $uri, ?array $body = null, ?string $rawBody = null): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $rawBody || null !== $body) {
            $server['CONTENT_TYPE'] = 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json';
        }
        $client->request($method, $uri, server: $server, content: $rawBody ?? (null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    protected function violationPaths(KernelBrowser $client): array
    {
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        $problem = $this->decode($client);
        self::assertIsArray($problem['violations']);
        $paths = array_values(array_unique(array_map(static fn (array $v): string => (string) $v['propertyPath'], $problem['violations'])));
        sort($paths);

        return $paths;
    }
}
