<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec: api-error-format — every failure under /api is RFC 9457 problem
 * details, and nothing internal leaks outside dev (APP_DEBUG=0 in .env.test).
 */
#[CoversNothing]
final class ProblemDetailsTest extends WebTestCase
{
    public function testUnknownApiRouteIs404ProblemDetails(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/does-not-exist');

        self::assertResponseStatusCodeSame(404);
        $problem = $this->assertProblemDetails($client->getResponse()->getContent(), 404);
        self::assertNotSame('', $problem['title']);
    }

    public function testWrongMethodIs405ProblemDetails(): void
    {
        $client = self::createClient();
        $client->request('DELETE', '/api/v1');

        self::assertResponseStatusCodeSame(405);
        $this->assertProblemDetails($client->getResponse()->getContent(), 405);
    }

    public function testUnexpectedExceptionIs500WithoutInternalDetails(): void
    {
        $client = self::createClient();
        $client->catchExceptions(true);
        $client->request('GET', '/api/v1/_test/boom');

        self::assertResponseStatusCodeSame(500);
        $body = (string) $client->getResponse()->getContent();
        $problem = $this->assertProblemDetails($body, 500);

        self::assertArrayNotHasKey('trace', $problem);
        self::assertStringNotContainsString('secret detail', $body);
        self::assertStringNotContainsString('/app/src', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertProblemDetails(string|false $body, int $status): array
    {
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json; charset=utf-8');
        self::assertIsString($body);
        $problem = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        foreach (['type', 'title', 'status', 'detail'] as $member) {
            self::assertArrayHasKey($member, $problem);
        }
        self::assertSame($status, $problem['status']);

        /** @var array<string, mixed> $problem */
        return $problem;
    }
}
