<?php

declare(strict_types=1);

namespace App\Tests\Web\Dashboard;

use App\Tests\Factory\LinkFactory;
use App\Tests\Fixture\ClickRows;
use App\Tests\Web\WebPageTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Spec web-ui — "The dashboard shows the signed-in user's own figures". */
#[CoversNothing]
final class DashboardTest extends WebPageTestCase
{
    public function testTheFiguresAndTheRecentLinksAreTheSignedInUsersOwn(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        $annsLink = LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        $beasLink = LinkFactory::createOne(['owner' => $bea, 'slug' => 'bea-one']);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        ClickRows::many($connection, $annsLink->getId(), 3, 'now', ['visitor' => 'a']);
        ClickRows::many($connection, $beasLink->getId(), 7, 'now', ['visitor' => 'b']);

        $this->signIn($client, 'ann@example.com');
        $crawler = $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('ann-one', $body);
        self::assertStringNotContainsString('bea-one', $body, "another owner's link is not on this dashboard");
        self::assertSame('3', $crawler->filter('article h2')->eq(1)->text(), "the click total counts only Ann's links");
    }

    public function testAnEmptyDashboardInvitesTheFirstLink(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');

        $this->signIn($client, 'ann@example.com');
        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No links yet');
    }

    public function testAGuestIsSentToTheLoginPage(): void
    {
        $client = self::createClient();

        $client->request('GET', '/dashboard');

        self::assertResponseRedirects('http://localhost/login');
        self::assertStringNotContainsString('Clicks per day', (string) $client->getResponse()->getContent());
    }
}
