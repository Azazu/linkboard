<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec: api-docs.
 */
#[CoversNothing]
final class ApiDocsTest extends WebTestCase
{
    public function testOpenApiDocumentIsServedAsJson(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs.json');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json; charset=utf-8');
        $document = $this->decode($client->getResponse()->getContent());
        self::assertIsString($document['openapi']);
        self::assertStringStartsWith('3.1', $document['openapi']);
        self::assertIsArray($document['info']);
        self::assertSame('Linkboard API', $document['info']['title']);
    }

    public function testEveryDocumentedPathIsVersioned(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs.json');

        $document = $this->decode($client->getResponse()->getContent());
        $paths = $document['paths'] ?? [];
        self::assertIsArray($paths);
        foreach (array_keys($paths) as $path) {
            self::assertStringStartsWith('/api/v1/', (string) $path);
        }
    }

    public function testSwaggerUiIsServedToBrowsers(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertStringContainsString('docs.json', (string) $client->getResponse()->getContent());
    }

    public function testBasePathServesTheOpenApiDocumentToApiClients(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        $fromBasePath = $client->getResponse()->getContent();

        $client->request('GET', '/api/docs.json');
        self::assertSame($client->getResponse()->getContent(), $fromBasePath);
    }

    public function testJsonLdIsNotAvailable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(406);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json; charset=utf-8');
    }

    public function testGraphQlEndpointIsAbsent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/graphql');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string|false $json): array
    {
        self::assertIsString($json);
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
