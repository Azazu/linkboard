<?php

declare(strict_types=1);

namespace App\Tests\Api\Analytics;

use App\Tests\Api\Link\LinkApiTestCase;
use App\Tests\Fixture\ClickRows;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * JWT-authenticated report requests over the test kernel; click rows through
 * the DBAL fixture; the report cache pool (namespaced `test`) cleared after
 * every test so entries never leak between tests or into the dev stack.
 */
abstract class AnalyticsApiTestCase extends LinkApiTestCase
{
    protected const string FROM = '2026-09-01T00:00:00Z';
    protected const string TO = '2026-09-08T00:00:00Z';
    protected const string PERIOD = 'from=2026-09-01T00:00:00Z&to=2026-09-08T00:00:00Z';

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $pool = self::getContainer()->get('cache.reports');
            if ($pool instanceof CacheItemPoolInterface) {
                $pool->clear();
            }
        }
        parent::tearDown();
    }

    protected static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @param array{country?: ?string, deviceType?: ?string, os?: ?string, browser?: ?string, bot?: bool, referer?: ?string, visitor?: string, variant?: ?string, resolvedBy?: string} $facts
     */
    protected static function click(Uuid $linkId, string $at, array $facts = [], int $count = 1): void
    {
        ClickRows::many(self::connection(), $linkId, $count, $at, $facts);
    }

    /**
     * @return array<string, mixed>
     */
    protected function get(KernelBrowser $client, string $token, string $uri, int $expected = 200): array
    {
        $this->api($client, $token, 'GET', $uri);
        self::assertResponseStatusCodeSame($expected, $uri);

        return $this->decode($client);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function violations(KernelBrowser $client): array
    {
        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        $violations = $this->decode($client)['violations'] ?? null;
        self::assertIsArray($violations);

        /** @var list<array<string, mixed>> $violations */
        return array_values($violations);
    }
}
