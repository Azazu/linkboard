<?php

declare(strict_types=1);

namespace App\Tests\Web\Stats;

use App\Tests\Factory\LinkFactory;
use App\Tests\Web\WebPageTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Uid\Uuid;

/**
 * Spec web-ui — "The statistics page is bounded by the same permission as the
 * report". The page asks the voter's own LINK_VIEW, the attribute the API's
 * report provider asks, and answers 404 on a denial so that another owner's
 * link is indistinguishable from an identifier no link has.
 */
#[CoversNothing]
final class LinkStatsAccessTest extends WebPageTestCase
{
    public function testOwnerAndAdministratorSeeItStrangerGets404(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $this->user('bea@example.com');
        $this->user('root@example.com', admin: true);
        $link = LinkFactory::createOne(['owner' => $ann, 'slug' => 'anns-secret']);
        $uri = '/links/'.$link->getId().'/stats';

        $this->signIn($client, 'ann@example.com');
        $client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/logout');
        $this->signIn($client, 'root@example.com');
        $client->request('GET', $uri);
        self::assertResponseIsSuccessful('an administrator may view any link’s reports, as the API grants');

        $client->request('GET', '/logout');
        $this->signIn($client, 'bea@example.com');
        $client->request('GET', $uri);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('anns-secret', (string) $client->getResponse()->getContent());
    }

    public function testAnIdentifierNoLinkHasAnswersTheSameAsAStrangersLink(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.Uuid::v7()->toRfc4122().'/stats');
        self::assertResponseStatusCodeSame(404);

        // a malformed id does not match the route at all, which is the same answer
        $client->request('GET', '/links/not-a-uuid/stats');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestIsSentToTheLoginPage(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann, 'slug' => 'anns-secret']);

        $client->request('GET', '/links/'.$link->getId().'/stats');

        self::assertResponseRedirects('/login');
        self::assertStringNotContainsString('anns-secret', (string) $client->getResponse()->getContent());
    }
}
