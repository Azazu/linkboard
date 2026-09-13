<?php

declare(strict_types=1);

namespace App\Tests\Web\Link;

use App\Link\Entity\Link;
use App\Tests\Factory\LinkFactory;
use App\Tests\Web\WebPageTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec web-ui — editing under the API's rules, and the routing-rules editor
 * with its violations.
 */
#[CoversNothing]
final class LinkEditTest extends WebPageTestCase
{
    public function testChangingTheTargetAndClearingTheExpiryDoesExactlyThat(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::new(['owner' => $ann, 'slug' => 'ann-one'])
            ->expiring(new \DateTimeImmutable('+1 year'))
            ->create();
        $createdAt = $link->getCreatedAt();
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->submitForm('Save changes', [
            'link[targetUrl]' => 'https://example.com/moved',
            'link[expiresAt]' => '',
        ]);

        self::assertResponseStatusCodeSame(303);
        $fresh = $this->reload($link->getId());
        self::assertSame('https://example.com/moved', $fresh->getTargetUrl());
        self::assertNull($fresh->getExpiresAt(), 'an emptied field clears');
        self::assertSame('ann-one', $fresh->getSlug(), 'the slug is immutable');
        self::assertSame(0, $fresh->getClickCount());
        self::assertSame($createdAt->getTimestamp(), $fresh->getCreatedAt()->getTimestamp());
    }

    public function testDeactivatingAndReactivatingFromTheForm(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->submitForm('Save changes', ['link[targetUrl]' => $link->getTargetUrl(), 'link[isActive]' => false]);
        self::assertFalse($this->reload($link->getId())->isActive());

        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->submitForm('Save changes', ['link[targetUrl]' => $link->getTargetUrl(), 'link[isActive]' => true]);
        self::assertTrue($this->reload($link->getId())->isActive());
    }

    public function testAValidRulesDocumentIsStoredInItsCanonicalForm(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->submitForm('Save changes', [
            'link[targetUrl]' => $link->getTargetUrl(),
            'link[rules][mode]' => 'raw',
            'link[rules][raw]' => '{"version":1,"rules":[{"match":{"device":["smartphone"]},"target":"https://example.com/m"}]}',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertEquals(
            ['version' => 1, 'rules' => [['match' => ['device' => ['smartphone']], 'target' => 'https://example.com/m']]],
            $this->reload($link->getId())->getRules(),
        );
    }

    public function testAnInvalidRulesDocumentKeepsTheStoredOneAndShowsEachViolation(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->submitForm('Save changes', [
            'link[targetUrl]' => $link->getTargetUrl(),
            'link[rules][mode]' => 'raw',
            'link[rules][raw]' => '{"version":1,"rules":[{"match":{"weather":["rain"]},"target":"javascript:alert(1)"}]}',
        ]);

        self::assertResponseStatusCodeSame(422);
        $alerts = $client->getCrawler()->filter('[role=alert]')->text();
        self::assertStringContainsString('[rules][0]', $alerts, 'the message keeps the path into the document');
        self::assertNull($this->reload($link->getId())->getRules(), 'the stored rules are untouched');
    }

    public function testTextThatIsNotJsonIsReportedOnTheDocumentField(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->submitForm('Save changes', [
            'link[targetUrl]' => $link->getTargetUrl(),
            'link[rules][mode]' => 'raw',
            'link[rules][raw]' => 'not json at all',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'not valid JSON');
    }

    public function testChoosingTheJsonViewIsWhatMakesTheServerReadIt(): void
    {
        // the mode is a control a person operates, not a hidden field a test
        // sets: a document typed into a view nobody chose must not be stored,
        // and one typed into the chosen view must be (Gate 2 round 1, finding 1)
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'ann@example.com');

        $crawler = $client->request('GET', '/links/'.$link->getId().'/edit');
        self::assertCount(2, $crawler->filter('input[name="link[rules][mode]"]'), 'both views are offered');
        self::assertCount(1, $crawler->filter('textarea[name="link[rules][raw]"]'), 'the document field is on the page without any scripting');

        // typed into the JSON field while the fields view stays chosen: not stored
        $client->submitForm('Save changes', [
            'link[targetUrl]' => $link->getTargetUrl(),
            'link[rules][raw]' => '{"version":1,"rules":[{"match":{"device":["tablet"]},"target":"https://example.com/t"}]}',
        ]);
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->reload($link->getId())->getRules(), 'a view nobody chose is not read');

        // the same document with the JSON view chosen: stored
        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->submitForm('Save changes', [
            'link[targetUrl]' => $link->getTargetUrl(),
            'link[rules][mode]' => 'raw',
            'link[rules][raw]' => '{"version":1,"rules":[{"match":{"device":["tablet"]},"target":"https://example.com/t"}]}',
        ]);
        self::assertResponseStatusCodeSame(303);
        self::assertEquals(
            ['version' => 1, 'rules' => [['match' => ['device' => ['tablet']], 'target' => 'https://example.com/t']]],
            $this->reload($link->getId())->getRules(),
        );
    }

    public function testADocumentTheRowsCannotHoldComesBackAsJson(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::new(['owner' => $ann])
            ->withRules(['version' => 1, 'variants' => [
                ['name' => 'a', 'weight' => 50, 'target' => 'https://example.com/a'],
                ['name' => 'b', 'weight' => 50, 'target' => 'https://example.com/b'],
            ]])
            ->create();
        $this->signIn($client, 'ann@example.com');

        $crawler = $client->request('GET', '/links/'.$link->getId().'/edit');

        self::assertSame('raw', $crawler->filter('input[name="link[rules][mode]"]:checked')->attr('value'), 'a document with variants opens as JSON');
        self::assertStringContainsString('"variants"', $crawler->filter('textarea[name="link[rules][raw]"]')->text());

        // and saving it unchanged keeps it, rather than dropping what rows cannot hold
        $client->submitForm('Save changes', ['link[targetUrl]' => $link->getTargetUrl()]);
        self::assertResponseStatusCodeSame(303);
        $stored = $this->reload($link->getId())->getRules();
        self::assertIsArray($stored);
        self::assertArrayHasKey('variants', $stored);
    }

    public function testTheStructuredEditorWritesTheSameDocument(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::createOne(['owner' => $ann]);
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->request('POST', '/links/'.$link->getId().'/edit', ['link' => [
            'targetUrl' => $link->getTargetUrl(),
            'rules' => [
                'mode' => 'structured',
                'rows' => [['matchKey' => 'country', 'values' => 'DE, AT', 'target' => 'https://example.com/de']],
            ],
            '_token' => $this->token($client),
        ]]);

        self::assertResponseStatusCodeSame(303);
        self::assertEquals(
            ['version' => 1, 'rules' => [['match' => ['country' => ['DE', 'AT']], 'target' => 'https://example.com/de']]],
            $this->reload($link->getId())->getRules(),
        );
    }

    public function testAnEmptiedEditorClearsTheRules(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $link = LinkFactory::new(['owner' => $ann])
            ->withRules(['version' => 1, 'rules' => [['match' => ['device' => ['tablet']], 'target' => 'https://example.com/t']]])
            ->create();
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/links/'.$link->getId().'/edit');
        $client->submitForm('Save changes', [
            'link[targetUrl]' => $link->getTargetUrl(),
            'link[rules][mode]' => 'raw',
            'link[rules][raw]' => '   ',
        ]);

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->reload($link->getId())->getRules());
    }

    public function testAStrangerCannotOpenOrPostTheEditForm(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $this->user('bea@example.com');
        $link = LinkFactory::createOne(['owner' => $ann, 'targetUrl' => 'https://example.com/original']);
        $this->signIn($client, 'bea@example.com');

        $client->request('GET', '/links/'.$link->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/links/'.$link->getId().'/edit', ['link' => ['targetUrl' => 'https://example.com/hijacked']]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('https://example.com/original', $this->reload($link->getId())->getTargetUrl());
    }

    private function reload(\Symfony\Component\Uid\Uuid $id): Link
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $link = $em->getRepository(Link::class)->find($id);
        self::assertInstanceOf(Link::class, $link);

        return $link;
    }

    private function token(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        $field = $client->getCrawler()->filter('input[name="link[_token]"]');

        return $field->count() > 0 ? (string) $field->attr('value') : '';
    }
}
