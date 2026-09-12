<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Link\Qr\LinkQrProcessor;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Spec qr-codes: "QR code of the short URL", "Format parameter", "Authorization
 * boundary of QR codes"; spec api-docs: the QR operation documented with its
 * image types.
 */
#[CoversClass(LinkQrProcessor::class)]
final class LinkQrTest extends LinkApiTestCase
{
    public function testSvgByDefaultWithHeadersAndPngOnRequest(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a, 'slug' => 'spring-sale']);
        $token = $this->token($client, 'a@example.com');

        $svg = $this->qr($client, $token, (string) $link->getId());
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename="spring-sale.svg"');
        self::assertSame(['max-age=86400', 'private'], self::cacheDirectives($client));
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'), 'no cookie');
        $root = simplexml_load_string($svg);
        self::assertNotFalse($root);
        self::assertSame(['svg', '512px', '512px'], [$root->getName(), (string) $root['width'], (string) $root['height']]);

        $png = $this->qr($client, $token, (string) $link->getId(), '?format=png');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('Content-Disposition', 'inline; filename="spring-sale.png"');
        self::assertSame(['max-age=86400', 'private'], self::cacheDirectives($client));
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertSame([512, 512, \IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);

        self::assertSame($svg, $this->qr($client, $token, (string) $link->getId(), '?format=svg'), 'format=svg is the default image');
    }

    public function testImageIsDeterministicAndEncodesEachLinkDifferently(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $one = LinkFactory::createOne(['owner' => $a, 'slug' => 'one-link']);
        $two = LinkFactory::createOne(['owner' => $a, 'slug' => 'two-link']);
        $token = $this->token($client, 'a@example.com');

        $first = $this->qr($client, $token, (string) $one->getId());
        $second = $this->qr($client, $token, (string) $one->getId());
        $other = $this->qr($client, $token, (string) $two->getId());

        self::assertSame($first, $second);
        self::assertNotSame($first, $other);
    }

    public function testUnknownFormatIs422AndJsonOnlyAcceptIs406(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $token = $this->token($client, 'a@example.com');

        foreach (['?format=gif', '?format='] as $query) {
            $this->qr($client, $token, (string) $link->getId(), $query);
            self::assertSame(['format'], $this->violationPaths($client), $query);
        }

        $this->api($client, $token, 'GET', '/api/v1/links/'.$link->getId().'/qr'); // Accept: application/json
        self::assertResponseStatusCodeSame(406);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testOwnerAdminStrangerAnonymousAndUnknownIds(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        UserFactory::createOne(['email' => 'b@example.com']);
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $id = (string) $link->getId();

        $this->qr($client, $this->token($client, 'a@example.com'), $id);
        self::assertResponseStatusCodeSame(200, 'owner');
        $this->qr($client, $this->token($client, 'admin@example.com'), $id);
        self::assertResponseStatusCodeSame(200, 'admin');
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');

        $b = $this->token($client, 'b@example.com');
        $this->qr($client, $b, $id);
        self::assertResponseStatusCodeSame(403, 'stranger');
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));

        foreach ([$id, 'not-a-uuid', Uuid::v7()->toRfc4122()] as $anyId) {
            $client->request('GET', '/api/v1/links/'.$anyId.'/qr');
            self::assertResponseStatusCodeSame(401, "anonymous, id $anyId");
        }

        foreach (['not-a-uuid', Uuid::v7()->toRfc4122()] as $unknown) {
            $this->qr($client, $b, $unknown);
            self::assertResponseStatusCodeSame(404, "authenticated, id $unknown");
            self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        }
    }

    public function testInactiveAndExpiredLinksKeepTheirCodeAndADeletedOneIs404(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $inactive = LinkFactory::new()->inactive()->create(['owner' => $a]);
        $expired = LinkFactory::new()->expiring(new \DateTimeImmutable('-1 day'))->create(['owner' => $a]);
        $doomed = LinkFactory::createOne(['owner' => $a]);
        $token = $this->token($client, 'a@example.com');

        $this->qr($client, $token, (string) $inactive->getId());
        self::assertResponseStatusCodeSame(200, 'inactive');
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        $this->qr($client, $token, (string) $expired->getId());
        self::assertResponseStatusCodeSame(200, 'expired');
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');

        $this->api($client, $token, 'DELETE', '/api/v1/links/'.$doomed->getId());
        self::assertResponseStatusCodeSame(204);
        $this->qr($client, $token, (string) $doomed->getId());
        self::assertResponseStatusCodeSame(404, 'deleted');
    }

    public function testOpenApiDocumentsTheOperationWithBothImageTypes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs.json');
        self::assertResponseStatusCodeSame(200);
        $doc = $this->decode($client);

        self::assertIsArray($doc['paths']);
        self::assertArrayHasKey('/api/v1/links/{id}/qr', $doc['paths']);
        $get = $doc['paths']['/api/v1/links/{id}/qr']['get'];
        self::assertIsArray($get);
        $params = array_filter($get['parameters'], static fn (array $p): bool => 'format' === $p['name'] && 'query' === $p['in']);
        self::assertCount(1, $params, 'the format query parameter is documented');
        self::assertSame(['svg', 'png'], array_values($params)[0]['schema']['enum']);
        $content = array_keys($get['responses']['200']['content']);
        sort($content);
        self::assertSame(['image/png', 'image/svg+xml'], $content);
    }

    private function qr(KernelBrowser $client, string $token, string $id, string $query = ''): string
    {
        $client->request('GET', '/api/v1/links/'.$id.'/qr'.$query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return (string) $client->getResponse()->getContent();
    }

    /**
     * @return list<string>
     */
    private static function cacheDirectives(KernelBrowser $client): array
    {
        $directives = array_map(trim(...), explode(',', (string) $client->getResponse()->headers->get('Cache-Control')));
        sort($directives);

        return $directives;
    }
}
