<?php

declare(strict_types=1);

namespace App\Tests\Web\ApiKey;

use App\Auth\Entity\ApiKey;
use App\Tests\Factory\ApiKeyFactory;
use App\Tests\Web\WebPageTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

/** Spec web-ui — "The API-keys page shows a new key once". */
#[CoversNothing]
final class ApiKeyPageTest extends WebPageTestCase
{
    public function testANewKeysPlaintextIsShownOnceAndNeverAgain(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/api-keys');
        $client->submitForm('Create key', ['api_key[name]' => 'monitoring']);
        self::assertResponseStatusCodeSame(303, 'a write answers a redirect, so a reload cannot create a second key');
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        $secret = $crawler->filter('[data-secret-target=value]')->text();
        self::assertMatchesRegularExpression('/^lb_[A-Za-z0-9]{40}$/', $secret);

        $again = $client->request('GET', '/api-keys');
        self::assertStringNotContainsString($secret, $again->filter('body')->text(), 'the value is not sent a second time');
        self::assertStringContainsString(substr($secret, 0, 8), $again->filter('table')->text(), 'only the prefix remains');
    }

    public function testThePageThatCarriedTheSecretIsKeptByNothing(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/api-keys');
        $client->submitForm('Create key', ['api_key[name]' => 'monitoring']);
        $crawler = $client->followRedirect();

        // the three markings of design decision 9a, each of which a browser honours
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('meta[name="turbo-cache-control"][content="no-cache"]'));
        self::assertCount(1, $crawler->filter('[data-turbo-temporary] [data-secret-target=value]'));
        self::assertCount(1, $crawler->filter('[data-controller=secret]'));

        // and the page without a secret is an ordinary page again
        $client->request('GET', '/api-keys');
        self::assertStringNotContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(0, $client->getCrawler()->filter('meta[name="turbo-cache-control"]'));
    }

    public function testThePageCarriesTheHardeningHeadersToo(): void
    {
        // the CSP exclusion is bounded to the API segment, so this page — which
        // renders a secret — is inside the policy (Gate 1 round 1, finding 2)
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/api-keys');
        self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy'));

        $client->submitForm('Create key', ['api_key[name]' => 'monitoring']);
        $client->followRedirect();
        $policy = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
    }

    public function testTheListHoldsTheOwnersKeysIncludingRevokedAndExpiredOnes(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        ApiKeyFactory::createOne(['owner' => $ann, 'name' => 'ann-active']);
        ApiKeyFactory::new(['owner' => $ann, 'name' => 'ann-revoked'])->revoked()->create();
        ApiKeyFactory::new(['owner' => $ann, 'name' => 'ann-expired'])->expired()->create();
        ApiKeyFactory::createOne(['owner' => $bea, 'name' => 'bea-secret']);

        $this->signIn($client, 'ann@example.com');
        $table = $client->request('GET', '/api-keys')->filter('table')->text();

        self::assertStringContainsString('ann-active', $table);
        self::assertStringContainsString('ann-revoked', $table);
        self::assertStringContainsString('ann-expired', $table);
        self::assertStringNotContainsString('bea-secret', $table);
    }

    public function testRevokingTheOwnKeyAndRefusingAnotherUsersOne(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $bea = $this->user('bea@example.com');
        $annsKey = ApiKeyFactory::createOne(['owner' => $ann, 'name' => 'ann-active']);
        $annsSecond = ApiKeyFactory::createOne(['owner' => $ann, 'name' => 'ann-second']);
        $beasKey = ApiKeyFactory::createOne(['owner' => $bea, 'name' => 'bea-secret']);

        $this->signIn($client, 'ann@example.com');
        $client->request('GET', '/api-keys');

        // revoking asks first, and reaching the question changes nothing
        $client->clickLink('Revoke '.$annsKey->getPrefix().'…');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Revoke this key?');
        self::assertSelectorTextContains('[role=alert]', 'stops working');
        self::assertFalse($this->reload($annsKey->getId())->isRevoked());

        $client->clickLink('Cancel');
        self::assertFalse($this->reload($annsKey->getId())->isRevoked(), 'cancelling leaves the key usable');

        $client->request('GET', '/api-keys/'.$annsKey->getId().'/revoke');
        $client->submitForm('Revoke '.$annsKey->getPrefix());

        self::assertResponseStatusCodeSame(303);
        self::assertTrue($this->reload($annsKey->getId())->isRevoked());

        // a token from Ann's own confirmation page: the refusal is about
        // ownership, not the token
        $client->request('GET', '/api-keys/'.$annsSecond->getId().'/revoke');
        $token = (string) $client->getCrawler()->filter('input[name=_token]')->first()->attr('value');

        $client->request('POST', '/api-keys/'.$beasKey->getId().'/revoke', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->reload($beasKey->getId())->isRevoked(), "another user's key still authenticates");

        $client->request('POST', '/api-keys/'.$beasKey->getId().'/revoke', ['_token' => 'not-the-token']);
        self::assertResponseStatusCodeSame(403, 'a forged request is refused before anything is looked up');
    }

    public function testTheCapIsReportedAsAMessage(): void
    {
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        ApiKeyFactory::createMany(ApiKey::MAX_ACTIVE_PER_USER, ['owner' => $ann]);

        $this->signIn($client, 'ann@example.com');
        $client->request('GET', '/api-keys');
        $client->submitForm('Create key', ['api_key[name]' => 'one too many']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'active API keys');
    }

    public function testEveryKeyIsReachableEvenPastAPageOfRevokedOnes(): void
    {
        // the cap of ten applies to active keys; revoked and expired ones pile up,
        // and an old active key must not fall off the end of the list
        // (Gate 2 round 1, finding 4)
        $client = self::createClient();
        $ann = $this->user('ann@example.com');
        $oldest = ApiKeyFactory::createOne(['owner' => $ann, 'name' => 'the-old-one', 'now' => new \DateTimeImmutable('-2 years')]);
        ApiKeyFactory::new(['owner' => $ann])->revoked()->many(55)->create();

        $this->signIn($client, 'ann@example.com');
        $first = $client->request('GET', '/api-keys');

        self::assertStringContainsString('56 in total', $first->filter('body')->text());
        self::assertStringNotContainsString('the-old-one', $first->filter('table')->text(), 'it is not on the first page');

        $client->clickLink('Next');
        self::assertStringContainsString('the-old-one', $client->getCrawler()->filter('table')->text(), 'but it is reachable');
        self::assertStringContainsString('Page 2 of 2', $client->getCrawler()->filter('body')->text());

        $client->clickLink('Revoke '.$oldest->getPrefix().'…');
        $client->submitForm('Revoke '.$oldest->getPrefix());
        self::assertTrue($this->reload($oldest->getId())->isRevoked());
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $client = self::createClient();
        $this->user('ann@example.com');
        $this->signIn($client, 'ann@example.com');

        $client->request('GET', '/api-keys');
        $client->submitForm('Create key', ['api_key[name]' => '  ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role=alert]', 'needs a name');
    }

    public function testAGuestIsSentToTheLoginPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api-keys');

        self::assertResponseRedirects('http://localhost/login');
    }

    private function reload(\Symfony\Component\Uid\Uuid $id): ApiKey
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $key = $em->getRepository(ApiKey::class)->find($id);
        self::assertInstanceOf(ApiKey::class, $key);

        return $key;
    }
}
