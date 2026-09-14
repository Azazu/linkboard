<?php

declare(strict_types=1);

namespace App\Tests\Web\Link;

use App\Tests\Factory\LinkFactory;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec web-ui — "A short link whose slug begins with a page's name is still a
 * short link". An access-control pattern is an unanchored regular expression
 * and ReservedSlugs reserves exact strings, so `^/(dashboard|links|api-keys)`
 * without a segment boundary would send these public redirects to the login
 * page (Gate 1 round 1, finding 1).
 */
#[CoversNothing]
final class PrefixSlugTest extends WebPageTestCase
{
    /** @return iterable<string, array{string}> */
    public static function prefixSlugs(): iterable
    {
        yield 'dashboard-sale' => ['dashboard-sale'];
        yield 'links-promo' => ['links-promo'];
        yield 'api-keys-promo' => ['api-keys-promo'];
    }

    #[DataProvider('prefixSlugs')]
    public function testAGuestFollowingSuchALinkIsRedirectedToItsTargetAndGetsNoSession(string $slug): void
    {
        $client = self::createClient();
        LinkFactory::createOne(['slug' => $slug, 'targetUrl' => 'https://example.com/campaign']);

        $client->request('GET', '/'.$slug);

        self::assertResponseRedirects('https://example.com/campaign', 302);
        self::assertNull($client->getCookieJar()->get('MOCKSESSID'), 'a redirect starts no session');
    }

    #[DataProvider('prefixSlugs')]
    public function testAnUnknownSuchSlugIsTheRedirectsOwnNotFoundPageNotTheLoginPage(string $slug): void
    {
        $client = self::createClient();

        $client->request('GET', '/'.$slug);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('[role=alert]', 'link');
    }

    public function testThePagesThemselvesStillRefuseAGuest(): void
    {
        $client = self::createClient();

        foreach (['/dashboard', '/links', '/links/new', '/api-keys'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('http://localhost/login', 302, $path);
        }
    }
}
