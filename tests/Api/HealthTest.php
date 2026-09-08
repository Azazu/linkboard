<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec: health-check — the endpoint is outside the API contour, so it is
 * exercised with the plain WebTestCase client, not ApiTestCase.
 */
#[CoversNothing]
final class HealthTest extends WebTestCase
{
    public function testLivenessAnswersOkWithoutCaching(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health');

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testDeepProbeReportsEveryDependency(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health?deep=1');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            '{"status":"ok","checks":{"database":"ok","redis":"ok"}}',
            $client->getResponse()->getContent(),
        );
    }

    public function testHealthIsNotPartOfTheOpenApiDocument(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs.json');

        self::assertResponseStatusCodeSame(200);
        /** @var array{paths?: array<string, mixed>|list<mixed>} $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $paths = $document['paths'] ?? [];
        self::assertArrayNotHasKey('/health', \is_array($paths) ? $paths : []);
    }
}
