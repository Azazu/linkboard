<?php

declare(strict_types=1);

namespace App\Tests\Web\Link;

use App\Link\Entity\Link;
use App\Tests\Factory\LinkFactory;
use App\Tests\Web\WebPageTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Spec web-ui — links are created from the UI under the API's rules, and refused by them. */
#[CoversNothing]
final class LinkCreateTest extends WebPageTestCase
{
    public function testAValidSubmissionCreatesTheLinkAndGoesToItsPage(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/new');
        $client->submitForm('Create link', [
            'link[targetUrl]' => 'https://example.com/landing',
            'link[slug]' => 'launch-day',
            'link[utmSource]' => 'newsletter',
            'link[maxClicks]' => '50',
        ]);

        self::assertResponseStatusCodeSame(303);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'launch-day');

        $link = $this->link('launch-day');
        self::assertSame('https://example.com/landing', $link->getTargetUrl());
        self::assertSame(['utm_source' => 'newsletter'], $link->getUtm());
        self::assertSame(50, $link->getMaxClicks());
        self::assertNull($link->getRules());
    }

    public function testAGeneratedSlugIsUsedWhenNoneIsGiven(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/new');
        $client->submitForm('Create link', ['link[targetUrl]' => 'https://example.com/generated']);

        self::assertResponseStatusCodeSame(303);
        $crawler = $client->followRedirect();
        self::assertMatchesRegularExpression('/[A-Za-z0-9]{7}/', $crawler->filter('h1')->text());
    }

    public function testARefusedTargetKeepsThePageAndCreatesNothing(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/new');
        $client->submitForm('Create link', ['link[targetUrl]' => 'javascript:alert(1)']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role=alert]');
        self::assertSame(0, $this->linkCount());
    }

    /**
     * The page refuses a slug another link already uses. Two independent guards
     * produce this one outcome — the Slug constraint when the form is validated,
     * and SlugTaken from the use case when a row appears between that check and
     * the insert — and each has its own witness: this test for what a person
     * sees, and CreateLinkTest for the race the constraint cannot see.
     */
    public function testASlugAnotherLinkAlreadyUsesIsReportedOnItsField(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        LinkFactory::createOne(['owner' => $ann, 'slug' => 'taken-one']);
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/new');
        $client->submitForm('Create link', [
            'link[targetUrl]' => 'https://example.com/second',
            'link[slug]' => 'taken-one',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'taken');
        self::assertSame(1, $this->linkCount(), 'the second link was not created');
    }

    public function testAnExpiryInThePastIsRefused(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/new');
        $client->submitForm('Create link', [
            'link[targetUrl]' => 'https://example.com/late',
            'link[expiresAt]' => '2020-01-01T00:00',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'future');
        self::assertSame(0, $this->linkCount());
    }

    public function testASubmissionWithoutAValidTokenCreatesNothing(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        // no GET of the form first, and no Origin or Referer: the stateless CSRF
        // check has nothing to match, exactly as in LoginTest
        $client->request('POST', '/links/new', ['link' => ['targetUrl' => 'https://example.com/forged']]);

        self::assertSame(0, $this->linkCount());
        self::assertResponseStatusCodeSame(422);
    }

    private function link(string $slug): Link
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $link = $em->getRepository(Link::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Link::class, $link);

        return $link;
    }

    private function linkCount(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return (int) $em->createQuery('SELECT count(l.id) FROM '.Link::class.' l')->getSingleScalarResult();
    }
}
