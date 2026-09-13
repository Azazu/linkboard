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
        $crawler = $client->submitForm('Create key', ['api_key[name]' => 'monitoring']);

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
        $crawler = $client->submitForm('Create key', ['api_key[name]' => 'monitoring']);

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
        ApiKeyFactory::createOne(['owner' => $ann, 'name' => 'ann-second']);
        $beasKey = ApiKeyFactory::createOne(['owner' => $bea, 'name' => 'bea-secret']);

        $this->signIn($client, 'ann@example.com');
        $client->request('GET', '/api-keys');
        $client->submitForm('Revoke '.$annsKey->getPrefix());

        self::assertResponseStatusCodeSame(303);
        self::assertTrue($this->reload($annsKey->getId())->isRevoked());

        // a token from Ann's own page: the refusal is about ownership, not the token
        $client->request('GET', '/api-keys');
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
