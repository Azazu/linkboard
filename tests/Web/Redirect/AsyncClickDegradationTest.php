<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Click\Counter\RedisClickCounter;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\ThrowingClickCounter;
use App\Tests\Fixture\ThrowingTransport;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Spec redirect "Click limit is exact under concurrency" (seeding, limit
 * change, re-enabled limit, in-flight tail, dispatch failures then key loss)
 * and "Failures of the stores" over HTTP; spec click-logging "Messages for
 * deleted links are discarded" through a real Worker. The real Redis counter
 * with the `test:` prefix; stubs through the test container.
 */
#[CoversNothing]
final class AsyncClickDegradationTest extends RedirectWebTestCase
{
    private TestHandler $log;

    public function testTenRedirectsOnAFreshLimitedLinkLetExactlyThreeThrough(): void
    {
        $client = self::createClient();
        $link = LinkFactory::new()->limited(3)->create(['slug' => 'fresh']);

        $statuses = [];
        for ($i = 0; $i < 10; ++$i) {
            self::visit($client, '/fresh');
            $statuses[] = $client->getResponse()->getStatusCode();
        }

        self::assertSame([302, 302, 302, 410, 410, 410, 410, 410, 410, 410], $statuses);
        self::assertSame('3', self::counterValue($link->getId()));
        self::assertSame(3, self::clickCountOf($link->getId()));
        self::assertCount(3, self::rawClicksOf($link->getId()));
    }

    public function testSeedingFromThePersistedCount(): void
    {
        $client = self::createClient();
        $link = LinkFactory::new()->limited(3, 2)->create(['slug' => 'seeded']);

        $statuses = [];
        for ($i = 0; $i < 3; ++$i) {
            self::visit($client, '/seeded');
            $statuses[] = $client->getResponse()->getStatusCode();
        }

        self::assertSame([302, 410, 410], $statuses);
        self::assertSame('3', self::counterValue($link->getId()));
    }

    public function testALimitChangeAppliesToTheNextRedirect(): void
    {
        $client = self::createClient();
        [$link, $token] = $this->ownedLimitedLink($client, 'raise', 3);

        $this->visits($client, '/raise', 3, 302);
        self::assertSame('3', self::counterValue($link->getId()));
        self::visit($client, '/raise');
        self::assertResponseStatusCodeSame(410);

        $this->apiPatch($client, $token, '/api/v1/links/'.$link->getId(), ['maxClicks' => 5]);
        self::visit($client, '/raise');

        self::assertResponseStatusCodeSame(302);
        self::assertSame('4', self::counterValue($link->getId()));
    }

    public function testLimitReEnabledAfterUnlimitedRedirectsIsLiftedToThePersistedCount(): void
    {
        $client = self::createClient();
        [$link, $token] = $this->ownedLimitedLink($client, 'reenable', 3);
        $this->visits($client, '/reenable', 3, 302);
        $this->apiPatch($client, $token, '/api/v1/links/'.$link->getId(), ['maxClicks' => null]);
        $this->visits($client, '/reenable', 4, 302);
        self::assertSame('3', self::counterValue($link->getId()), 'unlimited redirects never touched the counter');
        self::assertSame(7, self::clickCountOf($link->getId()));

        $this->apiPatch($client, $token, '/api/v1/links/'.$link->getId(), ['maxClicks' => 10]);
        $this->visits($client, '/reenable', 3, 302);

        self::assertSame('10', self::counterValue($link->getId()), 'lifted to 7, then 8, 9, 10');
        self::visit($client, '/reenable');
        self::assertResponseStatusCodeSame(410);
    }

    public function testLimitReEnabledWhileUnlimitedPeriodMessagesAreStillQueued(): void
    {
        $client = self::createClient();
        [$link, $token] = $this->ownedLimitedLink($client, 'queued', 3);
        $this->visits($client, '/queued', 3, 302);
        self::assertSame(3, self::clickCountOf($link->getId()), 'the three limited redirects are persisted');
        $this->apiPatch($client, $token, '/api/v1/links/'.$link->getId(), ['maxClicks' => null]);
        $this->visits($client, '/queued', 4, 302); // NOT consumed: persisted clickCount stays 3
        self::assertSame(3, self::rawClickCountOf($link->getId()));
        self::assertCount(4, self::pendingMessages());

        $this->apiPatch($client, $token, '/api/v1/links/'.$link->getId(), ['maxClicks' => 10]);
        $this->visits($client, '/queued', 7, 302); // the key was 3 and is not lifted: the four queued clicks are the stated overshoot
        self::visit($client, '/queued');
        self::assertResponseStatusCodeSame(410);
        self::assertSame('10', self::counterValue($link->getId()));

        self::assertSame(14, self::clickCountOf($link->getId()), '3 + 4 + 7 once the queue is consumed');
        self::visit($client, '/queued');
        self::assertResponseStatusCodeSame(410, 'the persisted count answers before the counter');
        self::assertSame('10', self::counterValue($link->getId()), 'the key is untouched by the fast path');

        $this->apiPatch($client, $token, '/api/v1/links/'.$link->getId(), ['maxClicks' => 20]);
        self::visit($client, '/queued');
        self::assertResponseStatusCodeSame(302);
        self::assertSame('15', self::counterValue($link->getId()), 'lifted to 14, then incremented');
    }

    public function testInFlightTailOnceThirteenArePersisted(): void
    {
        $client = self::createClient();
        [$link, $token] = $this->ownedLimitedLink($client, 'inflight', 3, clickCount: 13);
        self::redis()->set(self::counterKey($link->getId()), '3');

        self::visit($client, '/inflight');
        self::assertResponseStatusCodeSame(410, 'from the persisted count, before the counter');
        self::assertSame('3', self::counterValue($link->getId()));

        $this->apiPatch($client, $token, '/api/v1/links/'.$link->getId(), ['maxClicks' => 20]);
        self::visit($client, '/inflight');
        self::assertResponseStatusCodeSame(302);
        self::assertSame('14', self::counterValue($link->getId()), 'lifted to 13, then incremented');
    }

    public function testCounterFailureIs503ForLimitedLinksOnly(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $limited = LinkFactory::new()->limited(10)->create(['slug' => 'capped']);
        $free = LinkFactory::createOne(['slug' => 'free']);
        self::getContainer()->set(RedisClickCounter::class, new ThrowingClickCounter());
        $this->captureLog();

        self::visit($client, '/capped');
        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Retry-After', '5');
        self::assertSame([], self::pendingMessages());
        $warnings = $this->records(Logger::WARNING);
        self::assertCount(1, $warnings);
        self::assertSame((string) $limited->getId(), $warnings[0]->context['link_id']);
        self::assertSame(\RuntimeException::class, $warnings[0]->context['exception']);

        self::visit($client, '/free');
        self::assertResponseStatusCodeSame(302);
        self::assertCount(1, self::pendingMessages());
        self::assertCount(1, self::clicksOf($free->getId()));
    }

    public function testTransportDownStillRedirectsBothLinkKindsAndKeepsTheIncrement(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $free = LinkFactory::createOne(['slug' => 'free', 'targetUrl' => 'https://example.com/f']);
        $limited = LinkFactory::new()->limited(10)->create(['slug' => 'capped', 'targetUrl' => 'https://example.com/c']);
        self::getContainer()->set('messenger.transport.async', new ThrowingTransport());
        $this->captureLog();

        self::visit($client, '/free');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/f');
        self::visit($client, '/capped');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/c');

        self::assertCount(0, self::rawClicksOf($free->getId()));
        self::assertCount(0, self::rawClicksOf($limited->getId()));
        self::assertSame('1', self::counterValue($limited->getId()), 'the limit was enforced before the dispatch failed');
        $errors = $this->records(Logger::ERROR);
        self::assertCount(2, $errors);
        self::assertSame([(string) $free->getId(), (string) $limited->getId()], array_map(static fn (LogRecord $r): string => (string) $r->context['link_id'], $errors));
        self::assertStringNotContainsString('203.0.113.7', $this->logJson());
        self::assertStringNotContainsString('Probe/1.0', $this->logJson());
    }

    public function testDispatchFailuresThenKeyLossShowTheStatedOvershoot(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::new()->limited(3)->create(['slug' => 'lossy']);
        self::getContainer()->set('messenger.transport.async', new ThrowingTransport());

        $this->visits($client, '/lossy', 3, 302);
        self::assertSame('3', self::counterValue($link->getId()));
        self::assertCount(0, self::rawClicksOf($link->getId()), 'nothing persisted: every dispatch failed');

        self::redis()->del(self::counterKey($link->getId()));

        $this->visits($client, '/lossy', 3, 302); // seeded from the persisted 0: the overshoot equals the three unpersisted accepted redirects
        self::visit($client, '/lossy');
        self::assertResponseStatusCodeSame(410);
    }

    public function testAMessageForADeletedLinkIsAcknowledgedOnItsFirstAttempt(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'doomed']);
        $linkId = $link->getId();
        $token = $this->token($client, 'a@example.com');

        self::visit($client, '/doomed');
        self::assertCount(1, self::pendingMessages());
        $client->request('DELETE', '/api/v1/links/'.$linkId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(204);
        $this->captureLog();

        self::assertSame(1, self::consumeAsync());

        self::assertSame(0, self::connection()->fetchOne('SELECT count(*) FROM clicks WHERE link_id = ?', [$linkId->toRfc4122()]));
        self::assertCount(1, self::acknowledgedMessages());
        self::assertCount(0, self::rejectedMessages());
        self::assertCount(0, self::pendingMessages(), 'not retried');
        self::assertCount(0, self::failedMessages(), 'not parked');
        $infos = $this->records(Logger::INFO);
        self::assertCount(1, $infos);
        self::assertSame('Click message discarded: the link no longer exists', $infos[0]->message);
        self::assertSame($linkId->toRfc4122(), $infos[0]->context['link_id']);
        self::assertTrue(Uuid::isValid((string) $infos[0]->context['click_id']));
        self::assertSame([], $this->records(Logger::WARNING), 'no failure record');
        self::assertSame([], $this->records(Logger::ERROR), 'no failure record');
    }

    /**
     * @return array{\App\Link\Entity\Link, string}
     */
    private function ownedLimitedLink(KernelBrowser $client, string $slug, int $maxClicks, int $clickCount = 0): array
    {
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::new()->limited($maxClicks, $clickCount)->create(['owner' => $owner, 'slug' => $slug]);

        return [$link, $this->token($client, 'a@example.com')];
    }

    private function visits(KernelBrowser $client, string $path, int $times, int $expectedStatus): void
    {
        for ($i = 0; $i < $times; ++$i) {
            self::visit($client, $path);
            self::assertResponseStatusCodeSame($expectedStatus, "visit $i of $path");
        }
    }

    private function captureLog(): void
    {
        $this->log = new TestHandler();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->log);
    }

    /** @return list<LogRecord> */
    private function records(int $level): array
    {
        return array_values(array_filter($this->log->getRecords(), static fn (LogRecord $r): bool => $r->level->value === $level));
    }

    private function logJson(): string
    {
        return json_encode(array_map(static fn (LogRecord $r): array => $r->toArray(), $this->log->getRecords()), \JSON_THROW_ON_ERROR);
    }
}
