<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;

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

    public function testDeepProbeIsRefusedInProductionWithProblemDetails(): void
    {
        // The prod kernel has no test client (framework.test is off there), so
        // the request goes through the kernel directly. Secrets come from the
        // process environment (.env.test is already loaded); nothing is
        // overridden here so later tests in the process are unaffected. The prod
        // container is cached without resource tracking, so a stale
        // var/cache/prod from an earlier configuration would test the wrong
        // thing: start from scratch.
        (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/cache/prod');
        $kernel = self::bootKernel(['environment' => 'prod', 'debug' => false]);

        $response = $kernel->handle(Request::create('/health?deep=1'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $problem = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(404, $problem['status']);
        foreach (['type', 'title', 'detail'] as $member) {
            self::assertArrayHasKey($member, $problem);
        }
    }

    public function testAnAdminJwtDoesNotUnlockTheDeepProbeInProduction(): void
    {
        // Until the API-keys change authorizes the probe for an admin API key,
        // prod refuses it for everyone — including an admin's JWT (spec
        // health-check, MODIFIED by add-users-and-security).
        (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/cache/prod');
        $kernel = self::bootKernel(['environment' => 'prod', 'debug' => false]);

        $request = Request::create('/health?deep=1', server: ['HTTP_AUTHORIZATION' => 'Bearer not-checked-here.admin.jwt']);
        $response = $kernel->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
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
