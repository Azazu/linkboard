<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Spec click-logging, every requirement, over HTTP. */
#[CoversNothing]
final class ClickLoggingTest extends RedirectWebTestCase
{
    private const array COLUMNS = ['id', 'occurred_at', 'country', 'device_type', 'os', 'browser', 'is_bot', 'referer_host', 'visitor_hash', 'variant', 'resolved_by', 'link_id'];

    public function testOneRedirectLeavesOneClickWithoutPersonalData(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'promo-1']);

        self::visit($client, '/promo-1', ['HTTP_REFERER' => 'https://News.Example.org/story?id=1']);
        self::assertResponseStatusCodeSame(302);

        $rows = self::clicksOf($link->getId());
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(self::COLUMNS, array_keys($row));
        self::assertSame('news.example.org', $row['referer_host']);
        self::assertFalse($row['is_bot']);
        self::assertSame('default', $row['resolved_by']);
        self::assertNull($row['country']);
        self::assertNull($row['device_type']);
        self::assertNull($row['os']);
        self::assertNull($row['browser']);
        self::assertNull($row['variant']);
        $json = json_encode($row, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('203.0.113.7', $json);
        self::assertStringNotContainsString('Probe/1.0', $json);

        $token = $this->token($client, 'a@example.com');
        self::assertSame(1, $this->apiGet($client, $token, '/api/v1/links/'.$link->getId())['clickCount']);
    }

    public function testNonRedirectsRecordNothing(): void
    {
        $client = self::createClient();
        $inactive = LinkFactory::new()->inactive()->limited(5, 2)->create(['slug' => 'inactive']);
        $expired = LinkFactory::new()->expiring(new \DateTimeImmutable('-1 minute'))->limited(5, 2)->create(['slug' => 'expired']);

        self::visit($client, '/nothing-here');
        self::visit($client, '/inactive');
        self::visit($client, '/expired');

        self::assertSame(0, (int) self::connection()->fetchOne('SELECT count(*) FROM clicks'));
        self::assertSame(2, self::clickCountOf($inactive->getId()));
        self::assertSame(2, self::clickCountOf($expired->getId()));
    }

    public function testVisitorHashIsStablePerIpAndUserAgent(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'hashed']);

        self::visit($client, '/hashed');
        self::visit($client, '/hashed');
        self::visit($client, '/hashed', ['HTTP_USER_AGENT' => 'Other/2.0']);

        $hashes = array_column(self::clicksOf($link->getId()), 'visitor_hash');
        self::assertCount(3, $hashes);
        self::assertSame($hashes[0], $hashes[1]);
        self::assertNotSame($hashes[0], $hashes[2]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', (string) $hashes[0]);
    }

    public function testRefererHostCases(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'ref']);

        self::visit($client, '/ref', ['HTTP_REFERER' => 'https://News.Example.org/story?id=1']);
        self::visit($client, '/ref');
        self::visit($client, '/ref', ['HTTP_REFERER' => 'http://localhost:8082/dashboard']); // APP_PUBLIC_URL host

        self::assertSame(['news.example.org', null, null], array_column(self::clicksOf($link->getId()), 'referer_host'));
    }

    public function testOversizedAndHostileHeadersNeverLoseAClick(): void
    {
        $client = self::createClient();
        $unlimited = LinkFactory::createOne(['slug' => 'unlimited']);
        $limited = LinkFactory::new()->limited(5)->create(['slug' => 'limited']);

        self::visit($client, '/unlimited', ['HTTP_USER_AGENT' => str_repeat('u', 8192), 'HTTP_REFERER' => 'https://example.org/'.str_repeat('r', 4096)]);
        self::assertResponseStatusCodeSame(302);
        foreach (['/unlimited', '/limited'] as $path) {
            self::visit($client, $path, ['HTTP_REFERER' => 'https://'.str_repeat('a', 300).'/']);
            self::assertResponseStatusCodeSame(302, "300-character host on $path");
            self::visit($client, $path, ['HTTP_REFERER' => "https://ex\xffample.com/"]);
            self::assertResponseStatusCodeSame(302, "invalid UTF-8 on $path");
        }

        $unlimitedRows = self::clicksOf($unlimited->getId());
        self::assertCount(3, $unlimitedRows);
        self::assertSame('example.org', $unlimitedRows[0]['referer_host']);
        self::assertNull($unlimitedRows[1]['referer_host']);
        self::assertNull($unlimitedRows[2]['referer_host']);
        $limitedRows = self::clicksOf($limited->getId());
        self::assertCount(2, $limitedRows);
        self::assertSame([null, null], array_column($limitedRows, 'referer_host'));
        self::assertSame(2, self::clickCountOf($limited->getId()));
    }

    public function testDeletingALinkCascadesToItsClicks(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'doomed']);
        self::visit($client, '/doomed');
        self::visit($client, '/doomed');
        self::assertCount(2, self::clicksOf($link->getId()));

        $token = $this->token($client, 'a@example.com');
        $client->request('DELETE', '/api/v1/links/'.$link->getId(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(0, self::clicksOf($link->getId()));
    }
}
