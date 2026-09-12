<?php

declare(strict_types=1);

namespace App\Tests\Api\Auth;

use App\Auth\Api\ApiKeys\CreateApiKeyProcessor;
use App\Auth\Api\ApiKeys\OwnApiKeyItemProvider;
use App\Auth\Api\ApiKeys\OwnApiKeysProvider;
use App\Auth\Api\ApiKeys\RevokeApiKeyProcessor;
use App\Tests\Api\Link\LinkApiTestCase;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

/**
 * Spec api-keys "Create an API key and see it once" and "List and revoke own keys".
 */
#[CoversClass(CreateApiKeyProcessor::class)]
#[CoversClass(RevokeApiKeyProcessor::class)]
#[CoversClass(OwnApiKeysProvider::class)]
#[CoversClass(OwnApiKeyItemProvider::class)]
final class ApiKeysTest extends LinkApiTestCase
{
    public function testPlaintextOnceAndHashedAtRest(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'POST', '/api/v1/api-keys', ['name' => 'ci deploy']);
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode($client);
        self::assertMatchesRegularExpression('/^lb_[A-Za-z0-9]{40}$/', $created['key']);
        self::assertSame(substr($created['key'], 0, 8), $created['prefix']);
        self::assertSame(['ci deploy', null, null, null], [$created['name'], $created['expiresAt'], $created['lastUsedAt'], $created['revokedAt']]);

        $row = $this->connection()->fetchAssociative('SELECT * FROM api_keys WHERE id = :id', ['id' => $created['id']]);
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $created['key']), $row['key_hash']);
        self::assertSame($created['prefix'], $row['prefix']);
        foreach ($row as $column => $value) {
            self::assertNotSame($created['key'], $value, "column $column must not hold the plaintext");
        }

        $this->api($client, $token, 'GET', '/api/v1/api-keys');
        self::assertResponseStatusCodeSame(200);
        $listed = $this->decode($client)['items'][0];
        self::assertSame($created['id'], $listed['id']);
        self::assertArrayNotHasKey('key', $listed, 'the plaintext is shown once');
        self::assertSame(['id', 'name', 'prefix', 'expiresAt', 'createdAt', 'lastUsedAt', 'revokedAt'], array_keys($listed));
    }

    public function testInvalidInputIs422PerField(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        foreach ([['name' => ''], ['name' => '   '], ['name' => str_repeat('x', 65)], ['name' => 'ok', 'expiresAt' => '2020-01-01T00:00:00Z']] as $i => $body) {
            $this->api($client, $token, 'POST', '/api/v1/api-keys', $body);
            self::assertSame([3 === $i ? 'expiresAt' : 'name'], $this->violationPaths($client), json_encode($body, \JSON_THROW_ON_ERROR));
        }
    }

    public function testEleventhActiveKeyIs409UntilOneIsRevoked(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['email' => 'a@example.com']);
        ApiKeyFactory::createMany(9, ['owner' => $user]);
        ApiKeyFactory::new()->revoked()->create(['owner' => $user]); // does not count
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'POST', '/api/v1/api-keys', ['name' => 'tenth']);
        self::assertResponseStatusCodeSame(201);
        $tenth = $this->decode($client)['id'];

        $this->api($client, $token, 'POST', '/api/v1/api-keys', ['name' => 'eleventh']);
        self::assertResponseStatusCodeSame(409);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('10', (string) $this->decode($client)['detail']);
        self::assertSame(10, (int) $this->connection()->fetchOne('SELECT count(*) FROM api_keys WHERE user_id = :u AND revoked_at IS NULL', ['u' => $user->getId()->toRfc4122()]), 'nothing was created');

        $this->api($client, $token, 'DELETE', '/api/v1/api-keys/'.$tenth);
        self::assertResponseStatusCodeSame(204);
        $this->api($client, $token, 'POST', '/api/v1/api-keys', ['name' => 'eleventh']);
        self::assertResponseStatusCodeSame(201, 'a revoked key frees its slot');
    }

    public function testOwnKeysOnlyAndRevocationRules(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $b = UserFactory::createOne(['email' => 'b@example.com']);
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $aKey = ApiKeyFactory::createOne(['owner' => $a, 'name' => 'a-key']);
        $bKey = ApiKeyFactory::createOne(['owner' => $b, 'name' => 'b-key']);
        $aToken = $this->token($client, 'a@example.com');

        $this->api($client, $aToken, 'GET', '/api/v1/api-keys');
        $items = $this->decode($client)['items'];
        self::assertSame(['a-key'], array_column($items, 'name'), 'only A\'s keys');
        self::assertSame(['id', 'name', 'prefix', 'expiresAt', 'createdAt', 'lastUsedAt', 'revokedAt'], array_keys($items[0]));

        $this->api($client, $aToken, 'DELETE', '/api/v1/api-keys/'.$aKey->getId());
        self::assertResponseStatusCodeSame(204);
        $this->api($client, $aToken, 'DELETE', '/api/v1/api-keys/'.$aKey->getId());
        self::assertResponseStatusCodeSame(204, 'idempotent');
        $this->api($client, $aToken, 'GET', '/api/v1/api-keys');
        self::assertNotNull($this->decode($client)['items'][0]['revokedAt']);

        foreach ([(string) $bKey->getId(), Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->api($client, $aToken, 'DELETE', '/api/v1/api-keys/'.$id);
            self::assertResponseStatusCodeSame(404, "A deleting $id");
            self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        }
        $this->api($client, $this->token($client, 'admin@example.com'), 'DELETE', '/api/v1/api-keys/'.$bKey->getId());
        self::assertResponseStatusCodeSame(404, 'an admin manages only their own keys');
        self::assertNull($this->connection()->fetchOne('SELECT revoked_at FROM api_keys WHERE id = :id', ['id' => $bKey->getId()->toRfc4122()]), "B's key untouched");
    }

    public function testOpenApiListsTheThreeOperations(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs.json');
        $paths = $this->decode($client)['paths'];
        self::assertIsArray($paths);
        self::assertSame(['get', 'post'], array_keys($paths['/api/v1/api-keys']));
        self::assertSame(['delete'], array_keys($paths['/api/v1/api-keys/{id}']));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
