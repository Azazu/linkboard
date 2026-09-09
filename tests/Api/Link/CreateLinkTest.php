<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec links: create, slug rules, target URL policy, optional fields.
 */
#[CoversNothing]
final class CreateLinkTest extends LinkApiTestCase
{
    public function testGeneratedSlugAndResponseShape(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => 'https://example.com/landing']);

        self::assertResponseStatusCodeSame(201);
        $link = $this->decode($client);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{7}$/', $link['slug']);
        self::assertSame('http://localhost:8082/'.$link['slug'], $link['shortUrl']);
        self::assertSame(0, $link['clickCount']);
        self::assertTrue($link['isActive']);
        self::assertNull($link['utm']);
        self::assertNull($link['expiresAt']);
        self::assertNull($link['maxClicks']);
        self::assertSame(['clickCount', 'createdAt', 'expiresAt', 'id', 'isActive', 'maxClicks', 'ownerId', 'shortUrl', 'slug', 'targetUrl', 'updatedAt', 'utm'], array_keys($this->sorted($link)));
    }

    public function testCustomSlugAndOptionalFields(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');
        $expiry = (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::RFC3339);

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => 'https://example.com', 'slug' => 'spring-sale_2026', 'utm' => ['utm_source' => 'newsletter', 'utm_campaign' => 'spring'], 'expiresAt' => $expiry, 'maxClicks' => 100]);

        self::assertResponseStatusCodeSame(201);
        $link = $this->decode($client);
        self::assertSame('spring-sale_2026', $link['slug']);
        self::assertSame(['utm_source' => 'newsletter', 'utm_campaign' => 'spring'], $link['utm']);
        self::assertSame(100, $link['maxClicks']);
        self::assertSame((new \DateTimeImmutable($expiry))->getTimestamp(), (new \DateTimeImmutable($link['expiresAt']))->getTimestamp());
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function rejectedPayloads(): iterable
    {
        yield 'reserved slug' => [['targetUrl' => 'https://e.com', 'slug' => 'admin'], ['slug']];
        yield 'too short' => [['targetUrl' => 'https://e.com', 'slug' => 'ab'], ['slug']];
        yield 'too long' => [['targetUrl' => 'https://e.com', 'slug' => str_repeat('a', 33)], ['slug']];
        yield 'bad characters' => [['targetUrl' => 'https://e.com', 'slug' => 'a b'], ['slug']];
        yield 'unknown utm key' => [['targetUrl' => 'https://e.com', 'utm' => ['utm_foo' => 'x']], ['utm[utm_foo]']];
        yield 'utm value too long' => [['targetUrl' => 'https://e.com', 'utm' => ['utm_source' => str_repeat('a', 256)]], ['utm[utm_source]']];
        yield 'past expiry' => [['targetUrl' => 'https://e.com', 'expiresAt' => '2020-01-01T00:00:00Z'], ['expiresAt']];
        yield 'zero limit' => [['targetUrl' => 'https://e.com', 'maxClicks' => 0], ['maxClicks']];
        yield 'empty target' => [['targetUrl' => ''], ['targetUrl']];
        yield 'missing target' => [[], ['targetUrl']];
        // `$` would match before a trailing newline; the anchor must be absolute
        yield 'slug with a trailing newline' => [['targetUrl' => 'https://e.com', 'slug' => "abc\n"], ['slug']];
        yield 'reserved slug with a trailing newline' => [['targetUrl' => 'https://e.com', 'slug' => "admin\n"], ['slug']];
        yield 'maximum-length slug with a trailing newline' => [['targetUrl' => 'https://e.com', 'slug' => str_repeat('a', 32)."\n"], ['slug']];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $paths
     */
    #[DataProvider('rejectedPayloads')]
    public function testRejectedPayloadIs422(array $payload, array $paths): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'POST', '/api/v1/links', $payload);

        self::assertSame($paths, $this->violationPaths($client));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedTargets(): iterable
    {
        foreach (['market://details?id=x', 'ftp://example.com/f', 'javascript:alert(1)', 'http://localhost/', 'http://127.0.0.1/', 'http://[::1]/', 'http://10.0.0.5/', 'http://172.16.9.1/', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data', 'http://[fe80::1]/', 'http://[fd00::1]/', 'not a url', 'https://example.com/'.str_repeat('a', 2049 - \strlen('https://example.com/')), 'http://127.1/', 'http://2130706433/', 'http://0x7f000001/', 'http://0177.0.0.1/', 'http://2852039166/', 'http://%31%32%37.0.0.1/', 'http://127.0.0.1./', 'http://１２７.０.０.１/', 'http://２８５２０３９１６６/', 'http://ｌｏｃａｌｈｏｓｔ/', 'http://127.0.0.1。/', 'http://2852039166．/'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('rejectedTargets')]
    public function testTargetUrlPolicyOverHttp(string $url): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => $url]);

        self::assertSame(['targetUrl'], $this->violationPaths($client));
    }

    public function testStoreLinksAreAccepted(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        foreach (['https://apps.apple.com/app/id123', 'https://play.google.com/store/apps/details?id=com.example'] as $url) {
            $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => $url]);
            self::assertResponseStatusCodeSame(201, $url);
        }
    }

    public function testSlugUniquenessIsCaseSensitive(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        LinkFactory::createOne(['owner' => $owner, 'slug' => 'Sale']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => 'https://e.com', 'slug' => 'sale']);
        self::assertResponseStatusCodeSame(201);

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => 'https://e.com', 'slug' => 'Sale']);
        self::assertSame(['slug'], $this->violationPaths($client));
    }

    public function testAnonymousCannotCreate(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/api/v1/links', ['targetUrl' => 'https://e.com']);

        self::assertResponseStatusCodeSame(401);
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
