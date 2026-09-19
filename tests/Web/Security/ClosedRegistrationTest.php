<?php

declare(strict_types=1);

namespace App\Tests\Web\Security;

use App\Auth\Registration\ClosedRegistration;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\Json;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Spec user-accounts, "Self-registration with email and password": the switch
 * closes BOTH entry points, or it has not closed registration.
 *
 * One file for both surfaces on purpose. The defect this guards against is a
 * rule that lives in one of them, so a test that only ever looks at one would
 * pass while the other stayed open (change stretch-public-hosting, design
 * decision 5).
 */
#[CoversClass(ClosedRegistration::class)]
final class ClosedRegistrationTest extends WebPageTestCase
{
    private ?string $saved = null;

    protected function setUp(): void
    {
        $this->saved = \is_string($_SERVER['REGISTRATION_ENABLED'] ?? null) ? $_SERVER['REGISTRATION_ENABLED'] : null;
    }

    protected function tearDown(): void
    {
        if (null === $this->saved) {
            unset($_SERVER['REGISTRATION_ENABLED'], $_ENV['REGISTRATION_ENABLED']);
        } else {
            $_SERVER['REGISTRATION_ENABLED'] = $this->saved;
            $_ENV['REGISTRATION_ENABLED'] = $this->saved;
        }
        parent::tearDown();
    }

    public function testTheWebFormIsGone(): void
    {
        $client = self::closedInstance();

        $client->request('GET', '/register');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheWebFormPostCreatesNothing(): void
    {
        // No CSRF token is obtained first, and that is the point rather than a
        // shortcut: the listener runs at priority 16, before the controller
        // and before the form is validated, so a POST is refused with 404
        // where a reachable controller would have answered 422.
        $client = self::closedInstance();
        $before = UserFactory::repository()->count();

        $client->request('POST', '/register', [
            'registration' => ['email' => 'new@example.com', 'password' => 'correct-horse-battery'],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, UserFactory::repository()->count(), 'no account was created');
    }

    public function testTheApiOperationIsGoneAndAnswersProblemDetails(): void
    {
        $client = self::closedInstance();
        $before = UserFactory::repository()->count();

        $client->request('POST', '/api/v1/auth/register', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => 'new@example.com', 'password' => 'correct-horse-battery'], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(404, Json::decode($client->getResponse()->getContent())['status'] ?? null);
        self::assertSame($before, UserFactory::repository()->count(), 'no account was created');
    }

    public function testTheSignInPageStopsOfferingIt(): void
    {
        $client = self::closedInstance();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/register"]'), 'the page offers no form the listener would refuse');
    }

    public function testWithTheSwitchOnEverythingIsAsItWas(): void
    {
        $_SERVER['REGISTRATION_ENABLED'] = 'true';
        $_ENV['REGISTRATION_ENABLED'] = 'true';
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');
        self::assertGreaterThan(0, $crawler->filter('a[href="/register"]')->count(), 'the link is back');

        $client->request('GET', '/register');
        self::assertResponseIsSuccessful();
    }

    public function testAnAccountThatAlreadyExistsIsUntouched(): void
    {
        $client = self::closedInstance();
        $this->user('already@example.com');

        // the web
        $this->signIn($client, 'already@example.com');
        self::assertResponseIsSuccessful();

        // and the API
        $client->request('POST', '/api/v1/auth/token', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => 'already@example.com', 'password' => UserFactory::PASSWORD], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame('', Json::string(Json::decode($client->getResponse()->getContent()), 'token'));
    }

    private static function closedInstance(): KernelBrowser
    {
        // set before the client exists: the container resolves the env var on
        // boot, and a kernel that is already up has resolved it
        $_SERVER['REGISTRATION_ENABLED'] = 'false';
        $_ENV['REGISTRATION_ENABLED'] = 'false';

        return self::createClient();
    }
}
