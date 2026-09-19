<?php

declare(strict_types=1);

namespace App\Tests\Web\Security;

use App\Shared\Demo\DemoDataset;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec deployment, "The public demo instance exposes one account and no
 * registration": the credentials are published by the instance itself, which
 * is how they stay out of this repository (change stretch-public-hosting,
 * design decision 7).
 */
#[CoversNothing]
final class DemoCredentialsTest extends WebPageTestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['DEMO_INSTANCE', 'DEMO_PASSWORD'] as $name) {
            $this->saved[$name] = \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            // restored rather than unset: `.env` declares both, and a later
            // test in this process could not build its container without them
            $_SERVER[$name] = $value ?? '';
            $_ENV[$name] = $value ?? '';
        }
        parent::tearDown();
    }

    public function testADemoInstanceStatesThemOnItsSignInPage(): void
    {
        $published = 'published-by-the-instance-not-the-repository';
        self::arrange(demo: true, password: $published);
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $article = $crawler->filter('.demo-credentials');
        self::assertCount(1, $article);
        self::assertStringContainsString(DemoDataset::USER_EMAIL, $article->text());
        self::assertStringContainsString($published, $article->text());
    }

    public function testAnInstanceThatIsNotADemoStatesNothing(): void
    {
        self::arrange(demo: false, password: 'published-by-the-instance-not-the-repository');
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.demo-credentials'), 'only a demo publishes credentials');
    }

    public function testADemoWithNoPasswordConfiguredStatesNothing(): void
    {
        // the seed then generates one per run, and a credential nobody can
        // read is worse than none at all being offered
        self::arrange(demo: true, password: '');
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.demo-credentials'));
    }

    private static function arrange(bool $demo, string $password): void
    {
        $_SERVER['DEMO_INSTANCE'] = $demo ? 'true' : 'false';
        $_ENV['DEMO_INSTANCE'] = $_SERVER['DEMO_INSTANCE'];
        $_SERVER['DEMO_PASSWORD'] = $password;
        $_ENV['DEMO_PASSWORD'] = $password;
    }
}
