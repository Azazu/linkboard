<?php

declare(strict_types=1);

namespace App\Tests\Web\Link;

use App\Link\Entity\Link;
use App\Tests\Factory\LinkFactory;
use App\Tests\Web\WebPageTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Spec web-ui — "Deleting asks first": a confirmed, CSRF-protected POST, never a GET. */
#[CoversNothing]
final class LinkDeleteTest extends WebPageTestCase
{
    public function testTheOwnerConfirmsAndTheLinkIsGone(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        $id = (string) $link->getId();
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$id);
        $client->clickLink('Delete ann-one…');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Delete this link?');
        self::assertSelectorTextContains('[role=alert]', 'cannot be undone');
        self::assertNotNull($this->find($id), 'reaching the confirmation deletes nothing');
        $client->submitForm('Delete ann-one');

        self::assertResponseStatusCodeSame(303);
        $crawler = $client->followRedirect();
        self::assertStringNotContainsString('ann-one', $crawler->filter('body')->text());
        self::assertNull($this->find($id));

        $client->request('GET', '/links/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGetDeletesNothingAndCancellingLeavesTheLink(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann, 'slug' => 'ann-one']);
        $this->signIn($client, 'ann@example.com');

        // a link in an e-mail opens the question, it does not answer it
        $client->request('GET', '/links/'.$link->getId().'/delete');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->find((string) $link->getId()));

        $client->clickLink('Cancel');
        self::assertSelectorTextContains('h1', 'ann-one');
        self::assertNotNull($this->find((string) $link->getId()), 'cancelling leaves the link alone');
    }

    public function testAPostWithoutAValidTokenDeletesNothing(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'ann@example.com');

        $client->request('POST', '/links/'.$link->getId().'/delete', ['_token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->find((string) $link->getId()));
    }

    public function testAStrangerWithAValidTokenGetsTheSameFourOhFourAndDeletesNothing(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        $annsLink = LinkFactory::createOne(['owner' => $ann]);
        $beasLink = LinkFactory::createOne(['owner' => $bea]);
        $this->signIn($client, 'bea@example.com');

        // a token from Bea's own page, so the refusal is about ownership and
        // nothing else: an existing link she does not own looks like no link
        $client->request('GET', '/links/'.$beasLink->getId().'/delete');
        $token = (string) $client->getCrawler()->filter('input[name=_token]')->attr('value');

        $client->request('POST', '/links/'.$annsLink->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/links/018f0000-0000-7000-8000-000000000099/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        self::assertNotNull($this->find((string) $annsLink->getId()));
    }

    private function find(string $id): ?Link
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        return $em->getRepository(Link::class)->find($id);
    }
}
