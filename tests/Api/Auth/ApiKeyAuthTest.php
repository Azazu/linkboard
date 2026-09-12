<?php

declare(strict_types=1);

namespace App\Tests\Api\Auth;

use App\Auth\ApiKey\ApiKeyTokenHandler;
use App\Auth\Entity\User;
use App\Tests\Api\Link\LinkApiTestCase;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Spec api-keys "API keys authenticate API requests"; spec authentication
 * "API key on the same header"; spec user-accounts "Blocked user with a valid
 * API key". Keys are created through the factory (the key API is task 3.1).
 */
#[CoversClass(ApiKeyTokenHandler::class)]
final class ApiKeyAuthTest extends LinkApiTestCase
{
    public function testKeyAuthenticatesAndMarksLastUsedOncePerMinute(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne(['email' => 'a@example.com']);
        $plaintext = ApiKeyFactory::plaintext();
        $key = ApiKeyFactory::new()->forPlaintext($plaintext)->create(['owner' => $user]);

        $this->withKey($client, $plaintext, 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('a@example.com', $this->decode($client)['email']);
        $first = $this->lastUsedAt($key->getId()->toRfc4122());
        self::assertNotNull($first, 'the first use is recorded');

        $this->withKey($client, $plaintext, 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200);
        self::assertSame($first, $this->lastUsedAt($key->getId()->toRfc4122()), 'a second use within the minute is not written');
    }

    public function testRevokedExpiredUnknownAndMalformedKeysAre401(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['email' => 'a@example.com']);
        $revoked = ApiKeyFactory::plaintext();
        $expired = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($revoked)->revoked()->create(['owner' => $user]);
        ApiKeyFactory::new()->forPlaintext($expired)->expired()->create(['owner' => $user]);

        foreach (['revoked' => $revoked, 'expired' => $expired, 'unknown' => ApiKeyFactory::plaintext(), 'malformed' => 'lb_short'] as $case => $presented) {
            $this->withKey($client, $presented, 'GET', '/api/v1/me');
            self::assertResponseStatusCodeSame(401, $case);
            self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'), $case);
            self::assertSame(401, $this->decode($client)['status'], $case);
        }
    }

    public function testBlockedOwnerIs403BlockedUntilUnblocked(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $user = UserFactory::createOne(['email' => 'a@example.com']);
        $plaintext = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($plaintext)->create(['owner' => $user]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $this->block($em, $user->getEmail(), true);
        $this->withKey($client, $plaintext, 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(403);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame('blocked', $this->decode($client)['detail']);

        $this->block($em, $user->getEmail(), false);
        $this->withKey($client, $plaintext, 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200, 'unblocking makes the same key work again');
    }

    public function testJwtStillWorksAndAKeyIsSubjectToTheSameVoters(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $b = UserFactory::createOne(['email' => 'b@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $bKey = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($bKey)->create(['owner' => $b]);

        $this->api($client, $this->token($client, 'a@example.com'), 'GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(200, 'a JWT on the same firewall');

        $this->withKey($client, $bKey, 'GET', '/api/v1/links/'.$link->getId());
        self::assertResponseStatusCodeSame(403, 'a stranger with a key meets the link voter');
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

        $aKey = ApiKeyFactory::plaintext();
        ApiKeyFactory::new()->forPlaintext($aKey)->create(['owner' => $a]);
        $this->withKey($client, $aKey, 'GET', '/api/v1/links/'.$link->getId());
        self::assertResponseStatusCodeSame(200, 'the owner with a key');
    }

    private function withKey(KernelBrowser $client, string $key, string $method, string $uri): void
    {
        $client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'HTTP_ACCEPT' => 'application/json']);
    }

    private function block(EntityManagerInterface $em, string $email, bool $blocked): void
    {
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $blocked ? $user->block(new \DateTimeImmutable()) : $user->unblock(new \DateTimeImmutable());
        $em->flush();
        $em->clear();
    }

    private function lastUsedAt(string $id): ?string
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $value = $connection->fetchOne('SELECT last_used_at FROM api_keys WHERE id = :id', ['id' => $id]);

        return \is_string($value) ? $value : null;
    }
}
