<?php

declare(strict_types=1);

namespace App\Tests\Api\GraphQl;

use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\Json;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec graphql-api "Authorization is the same as the REST API's".
 *
 * The providers are the same classes the REST operations use, so the work here
 * is proving the guarantee rather than building it — from every angle the
 * capability names: a stranger, an anonymous caller, a plain user against the
 * global reports, and the credential failures the firewall decides.
 */
#[CoversNothing]
final class AuthorizationTest extends GraphQlTestCase
{
    public function testAnOwnerReadsTheirOwnLink(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'mine']);

        $this->post($client, $this->token($client, 'owner@example.com'), \sprintf('{ link(id: "/api/v1/links/%s") { slug } }', $link->getId()));

        self::assertSame('mine', Json::string(Json::map($this->data($client), 'link'), 'slug'));
    }

    public function testAStrangerCannotReadSomeoneElsesLinkAndIsRefusedAsTheApiRefusesThem(): void
    {
        // GraphQL mirrors the REST API, and the REST API distinguishes on
        // purpose: ADR-005 keeps 403 for a link the caller may not see and 404
        // for one that does not exist, because "403 tells an integrator
        // plainly" and the enumeration that costs is accepted there and
        // mitigated by the rate limit. It is the *pages* that answer 404 to
        // both. Carrying the pages' property here would have made GraphQL
        // disagree with the protocol it mirrors, which is what this test says
        // instead of what it said when it was written.
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com']);
        UserFactory::createOne(['email' => 'stranger@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'not-yours']);
        $stranger = $this->token($client, 'stranger@example.com');

        $this->post($client, $stranger, \sprintf('{ link(id: "/api/v1/links/%s") { slug } }', $link->getId()));
        self::assertStringContainsString('Access Denied', $this->messages($client), 'refused as the REST API refuses it');
        self::assertNull(Json::mapAt(Json::decode($client->getResponse()->getContent()), 'data')['link'] ?? null);

        $this->post($client, $stranger, '{ link(id: "/api/v1/links/01920f3a-0000-7000-8000-000000000000") { slug } }');
        self::assertStringContainsString('No such link', $this->messages($client), 'and a missing link is missing, as over REST');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linkReports(): iterable
    {
        foreach (['linkSummaryReport' => 'summary', 'linkTimeseriesReport' => 'timeseries', 'linkCountriesReport' => 'countries',
            'linkDevicesReport' => 'devices', 'linkReferrersReport' => 'referrers', 'linkVariantsReport' => 'variants'] as $query => $path) {
            yield $query => [$query.'(id: "/api/v1/links/%s/stats/'.$path.'") { linkId }'];
        }
    }

    #[DataProvider('linkReports')]
    public function testAStrangerCannotReadSomeoneElsesReports(string $selection): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'owner@example.com']);
        UserFactory::createOne(['email' => 'stranger@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner]);

        $this->post($client, $this->token($client, 'stranger@example.com'), '{ '.\sprintf($selection, $link->getId()).' }');

        self::assertNotSame([], $this->errors($client));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function globalReports(): iterable
    {
        yield 'adminSummaryReport' => ['adminSummaryReport(id: "/api/v1/admin/stats/summary") { totalClicks }'];
        yield 'adminTimeseriesReport' => ['adminTimeseriesReport(id: "/api/v1/admin/stats/timeseries") { granularity }'];
        yield 'adminTopLinksReport' => ['adminTopLinksReport(id: "/api/v1/admin/stats/top-links") { total }'];
    }

    #[DataProvider('globalReports')]
    public function testTheGlobalReportsStillRequireAnAdministrator(string $selection): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'plain@example.com']);

        $this->post($client, $this->token($client, 'plain@example.com'), '{ '.$selection.' }');

        self::assertNotSame([], $this->errors($client));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothPaths(): iterable
    {
        yield 'the documented path' => [self::PATH];
        yield 'the framework path' => [self::UNVERSIONED_PATH];
    }

    #[DataProvider('bothPaths')]
    public function testAnAnonymousCallerIsRefusedByTheFirewallAtEitherPath(string $path): void
    {
        $client = self::createClient();

        $this->post($client, null, '{ me { email } }', path: $path);

        // decided before the executor, so it keeps the shape every other
        // pre-controller refusal has (design decision 5)
        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    #[DataProvider('bothPaths')]
    public function testAMalformedTokenIsRefusedAtEitherPath(string $path): void
    {
        $client = self::createClient();

        $this->post($client, 'not-a-jwt', '{ me { email } }', path: $path);

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    #[DataProvider('bothPaths')]
    public function testAnUnknownApiKeyIsRefusedAtEitherPath(string $path): void
    {
        $client = self::createClient();

        $client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer lb_'.str_repeat('z', 40),
        ], content: json_encode(['query' => '{ me { email } }'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    #[DataProvider('bothPaths')]
    public function testABlockedAccountIsRefusedAtEitherPath(string $path): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'blocked@example.com']);
        $token = $this->token($client, 'blocked@example.com');

        $repository = self::getContainer()->get(UserRepositoryInterface::class);
        self::assertInstanceOf(UserRepositoryInterface::class, $repository);
        $user = $repository->findByEmail('blocked@example.com');
        self::assertNotNull($user);
        $user->block(new \DateTimeImmutable());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();

        $this->post($client, $token, '{ me { email } }', path: $path);

        // 403 with `blocked`, the same answer the REST API gives that account:
        // the user checker runs in the firewall, before any executor
        self::assertResponseStatusCodeSame(403);
        self::assertSame('blocked', Json::string(Json::decode($client->getResponse()->getContent()), 'detail'));
    }
}
