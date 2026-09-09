<?php

declare(strict_types=1);

namespace App\Tests\Api\Admin;

use App\Auth\Entity\User;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec: user-administration — listing, block/unblock, audit; the
 * authorization boundary matrix anonymous / user / admin / self.
 */
#[CoversNothing]
final class UserAdminTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAdminListsUsersWithoutPasswordMaterial(): void
    {
        $client = self::createClient();
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        UserFactory::createMany(2);

        $this->request($client, 'admin@example.com', 'GET', '/api/v1/admin/users?itemsPerPage=2');

        self::assertResponseStatusCodeSame(200);
        $body = (string) $client->getResponse()->getContent();
        $page = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($page);
        self::assertSame(3, $page['totalItems']);
        self::assertSame(1, $page['page']);
        self::assertSame(2, $page['itemsPerPage']);
        self::assertIsArray($page['items']);
        self::assertCount(2, $page['items']);
        self::assertSame(['createdAt', 'email', 'id', 'isBlocked', 'roles'], array_keys($this->sorted($page['items'][0])));
        self::assertStringNotContainsString('password', $body);
    }

    public function testRegularUserAndAnonymousAreRefused(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'ann@example.com']);

        $this->request($client, 'ann@example.com', 'GET', '/api/v1/admin/users');
        self::assertResponseStatusCodeSame(403);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

        $client->request('GET', '/api/v1/admin/users', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(401);

        $target = UserFactory::createOne();
        $this->request($client, 'ann@example.com', 'POST', '/api/v1/admin/users/'.$target->getId().'/block');
        self::assertResponseStatusCodeSame(403);
    }

    public function testBlockRefusesTheUsersValidTokenAndUnblockRestoresAccess(): void
    {
        $client = self::createClient();
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $user = UserFactory::createOne(['email' => 'ann@example.com']);
        $userToken = $this->token($client, 'ann@example.com');

        $this->request($client, 'admin@example.com', 'POST', '/api/v1/admin/users/'.$user->getId().'/block');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->decode($client)['isBlocked']);

        $client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$userToken, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('blocked', $this->decode($client)['detail']);

        // idempotent
        $this->request($client, 'admin@example.com', 'POST', '/api/v1/admin/users/'.$user->getId().'/block');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->decode($client)['isBlocked']);

        $this->request($client, 'admin@example.com', 'POST', '/api/v1/admin/users/'.$user->getId().'/unblock');
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->decode($client)['isBlocked']);

        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => 'ann@example.com', 'password' => UserFactory::PASSWORD]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testAdminCannotBlockThemselves(): void
    {
        $client = self::createClient();
        $admin = UserFactory::new()->admin()->create(['email' => 'admin@example.com']);

        $this->request($client, 'admin@example.com', 'POST', '/api/v1/admin/users/'.$admin->getId().'/block');

        self::assertResponseStatusCodeSame(422);
        $problem = $this->decode($client);
        self::assertIsArray($problem['violations']);
        self::assertSame('id', $problem['violations'][0]['propertyPath']);
        self::assertFalse($admin->isBlocked());
    }

    public function testUnknownOrMalformedIdIs404(): void
    {
        $client = self::createClient();
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);

        $this->request($client, 'admin@example.com', 'POST', '/api/v1/admin/users/00000000-0000-7000-8000-000000000000/block');
        self::assertResponseStatusCodeSame(404);
        $this->request($client, 'admin@example.com', 'POST', '/api/v1/admin/users/not-a-uuid/block');
        self::assertResponseStatusCodeSame(404);
    }

    public function testBlockAndUnblockEachWriteOneAuditRecordThroughTheStreamHandler(): void
    {
        // The test env uses the production wiring (always-on info stream, JSON,
        // audit channel only) with a file instead of stderr.
        $client = self::createClient();
        $log = self::getContainer()->getParameter('kernel.logs_dir').'/audit.log';
        \assert(\is_string($log));
        (new Filesystem())->dumpFile($log, '');
        $admin = UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $user = UserFactory::createOne(['email' => 'ann@example.com']);

        $this->request($client, 'admin@example.com', 'POST', '/api/v1/admin/users/'.$user->getId().'/block');
        self::assertResponseStatusCodeSame(200);
        $this->request($client, 'admin@example.com', 'POST', '/api/v1/admin/users/'.$user->getId().'/unblock');
        self::assertResponseStatusCodeSame(200);

        $lines = array_values(array_filter(explode("\n", file_get_contents($log) ?: '')));
        self::assertCount(2, $lines, 'exactly one record per action');
        foreach ([['user.block', $lines[0]], ['user.unblock', $lines[1]]] as [$action, $line]) {
            $record = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($record);
            self::assertSame('audit', $record['channel']);
            self::assertSame('INFO', $record['level_name']);
            self::assertSame($action, $record['message']);
            self::assertSame($action, $record['context']['action']);
            self::assertSame((string) $admin->getId(), $record['context']['actor_id']);
            self::assertSame((string) $user->getId(), $record['context']['target_id']);
            self::assertStringNotContainsString('@', $line);
        }
    }

    private function token(KernelBrowser $client, string $email): string
    {
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => $email, 'password' => UserFactory::PASSWORD]);
        $token = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function request(KernelBrowser $client, string $asEmail, string $method, string $uri): void
    {
        $token = $this->token($client, $asEmail);
        $client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function sorted(array $item): array
    {
        ksort($item);

        return $item;
    }
}
