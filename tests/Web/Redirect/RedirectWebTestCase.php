<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

abstract class RedirectWebTestCase extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @param array<string, string> $server
     */
    protected static function visit(KernelBrowser $client, string $path, array $server = [], string $method = 'GET'): void
    {
        $client->request($method, $path, server: $server + ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Probe/1.0']);
    }

    protected static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /** @return list<array<string, mixed>> */
    protected static function clicksOf(Uuid $linkId): array
    {
        return self::connection()->fetchAllAssociative('SELECT * FROM clicks WHERE link_id = ? ORDER BY occurred_at, id', [$linkId->toRfc4122()]);
    }

    protected static function clickCountOf(Uuid $linkId): int
    {
        return (int) self::connection()->fetchOne('SELECT click_count FROM links WHERE id = ?', [$linkId->toRfc4122()]);
    }

    protected function token(KernelBrowser $client, string $email): string
    {
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => $email, 'password' => UserFactory::PASSWORD]);
        $token = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /** @return array<string, mixed> */
    protected function apiGet(KernelBrowser $client, string $token, string $uri): array
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }
}
