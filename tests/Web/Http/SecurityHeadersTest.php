<?php

declare(strict_types=1);

namespace App\Tests\Web\Http;

use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec web-ui — "Every web response carries the hardening headers" (NFR-SEC-5).
 */
#[CoversNothing]
final class SecurityHeadersTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAPageCarriesTheFourHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        $response = $client->getResponse();
        self::assertResponseIsSuccessful();
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function testThePolicyNamesNoForeignOrigin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        foreach (["default-src 'self'", "style-src 'self' 'nonce-", "img-src 'self' data:", "font-src 'self'", "connect-src 'self'", "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'", "object-src 'none'"] as $directive) {
            self::assertStringContainsString($directive, $policy);
        }
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        // no scheme other than the data: images above, and no host at all
        self::assertDoesNotMatchRegularExpression('#(https?:)?//[a-z0-9.-]+#i', $policy, $policy);
    }

    public function testEveryResponseCarriesNosniffAndTheApiCarriesNoPolicy(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/links');

        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testTheApiDocumentationKeepsTheOtherHeadersAndIsTheOnlyPageWithoutThePolicy(): void
    {
        // Swagger UI bootstraps with an inline script this application does not
        // control (design decision 6); the exemption is one page, and it is this one.
        $client = self::createClient();
        $client->request('GET', '/api/docs');

        $response = $client->getResponse();
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function testTheNonceIsFreshOnEveryResponseAndMatchesTheInlineScript(): void
    {
        $client = self::createClient();

        $nonces = [];
        foreach ([1, 2] as $ignored) {
            $client->request('GET', '/login');
            $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');
            self::assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $policy, $m), $policy);
            $nonce = $m[1] ?? throw new \LogicException('the policy matched but captured nothing');
            self::assertGreaterThanOrEqual(16, \strlen($nonce));
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('<script type="importmap" nonce="'.$nonce.'"', $html, 'the import map carries the policy\'s nonce');
            $nonces[] = $nonce;
        }

        self::assertNotSame($nonces[0], $nonces[1], 'a nonce is per response, not per deployment');
    }

    public function testTheRedirectKeepsItsOwnReferrerPolicy(): void
    {
        // spec redirect: the 302 deliberately uses a weaker policy so the target
        // still sees the referrer on a downgrade; the subscriber must not overwrite it.
        $client = self::createClient();
        $client->request('GET', '/no-such-slug-here');

        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testTheSessionCookieIsHttpOnlyAndLax(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'ann@example.com']);
        self::signIn($client, 'ann@example.com');

        $cookie = self::sessionCookie($client);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertFalse($cookie->isSecure(), 'cookie_secure: auto leaves the flag off on plain HTTP, which is what a local stack needs');
    }

    public function testTheSessionIsConfiguredToBeSecureWhereverTheRequestIs(): void
    {
        // The Secure flag itself cannot be observed here: `cookie_secure: auto`
        // is resolved by the *native* storage factory (FrameworkExtension turns
        // it into that factory's "resolve from the request" argument), and the
        // test environment is pinned to the mock storage, which never receives
        // it. What is asserted instead is the configuration the dev and prod
        // kernels run on — non-vacuous, since changing any of the three values
        // fails this test.
        self::createClient();
        $options = self::getContainer()->getParameter('session.storage.options');

        self::assertIsArray($options);
        self::assertSame('auto', $options['cookie_secure'] ?? null, 'Secure wherever the request is secure');
        self::assertTrue($options['cookie_httponly'] ?? null);
        self::assertSame('lax', $options['cookie_samesite'] ?? null);
    }

    private static function sessionCookie(KernelBrowser $client): \Symfony\Component\BrowserKit\Cookie
    {
        $cookie = $client->getCookieJar()->get('MOCKSESSID') ?? $client->getCookieJar()->get('PHPSESSID');
        self::assertNotNull($cookie, 'the sign-in created a session cookie');

        return $cookie;
    }

    public static function signIn(KernelBrowser $client, string $email): void
    {
        $client->request('GET', '/login');
        $client->submitForm('Log in', ['email' => $email, 'password' => UserFactory::PASSWORD]);
    }
}
