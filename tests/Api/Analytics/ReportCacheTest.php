<?php

declare(strict_types=1);

namespace App\Tests\Api\Analytics;

use App\Analytics\Cache\ReportCache;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\StatementRecorder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

/**
 * Spec analytics "Report cache" and spec links "Cached reports are invalidated
 * by a patch" / "Cache store down during a patch" / "Reports gone with the
 * link" — over HTTP, cache hits proven by the recorded SQL statements.
 */
#[CoversNothing]
final class ReportCacheTest extends AnalyticsApiTestCase
{
    public function testSecondIdenticalRequestIsServedFromTheCache(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        self::click($link->getId(), '2026-09-02T10:00:00Z', [], 3);
        $token = $this->token($client, 'a@example.com');
        $uri = "/api/v1/links/{$link->getId()}/stats/summary?".self::PERIOD;

        $first = $this->get($client, $token, $uri);
        StatementRecorder::reset();
        $second = $this->get($client, $token, $uri);

        self::assertSame($first, $second);
        self::assertSame([], self::clickStatements(), 'the second request must not query the click records');
        self::assertSame(3, $second['clicksInPeriod']);
    }

    public function testDifferentParametersAreDifferentEntries(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        self::click($link->getId(), '2026-09-02T10:00:00Z', [], 3);
        self::click($link->getId(), '2026-09-02T10:00:00Z', ['bot' => true], 2);
        $token = $this->token($client, 'a@example.com');
        $uri = "/api/v1/links/{$link->getId()}/stats/summary?".self::PERIOD;

        $this->get($client, $token, $uri);
        StatementRecorder::reset();
        $withBots = $this->get($client, $token, $uri.'&includeBots=true');

        self::assertNotSame([], self::clickStatements(), 'a new parameter set is computed');
        self::assertSame(5, $withBots['clicksInPeriod']);
    }

    public function testAPatchInvalidatesTheLinkAndARejectedPatchDoesNot(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        self::click($link->getId(), '2026-09-02T10:00:00Z', [], 3);
        $token = $this->token($client, 'a@example.com');
        $uri = "/api/v1/links/{$link->getId()}/stats/summary?".self::PERIOD;
        $linkUri = '/api/v1/links/'.$link->getId();

        $first = $this->get($client, $token, $uri);
        self::click($link->getId(), '2026-09-03T10:00:00Z'); // recorded after the report was cached

        $this->api($client, $token, 'PATCH', $linkUri, ['targetUrl' => 'http://10.0.0.1/']); // rejected
        self::assertResponseStatusCodeSame(422);
        self::assertSame($first, $this->get($client, $token, $uri), 'a rejected patch leaves the cached report');

        $this->api($client, $token, 'PATCH', $linkUri, ['isActive' => false]);
        self::assertResponseStatusCodeSame(200);
        StatementRecorder::reset();
        $after = $this->get($client, $token, $uri);

        self::assertSame(4, $after['clicksInPeriod']);
        self::assertNotSame([], self::clickStatements(), 'recomputed after the patch');
    }

    public function testDeletionInvalidatesTheLinkAndTheGlobalReports(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $link = LinkFactory::createOne(['owner' => $a, 'slug' => 'doomed']);
        $other = LinkFactory::createOne(['owner' => $a, 'slug' => 'stays']);
        self::click($link->getId(), '2026-09-02T10:00:00Z', [], 5);
        self::click($other->getId(), '2026-09-02T10:00:00Z', [], 1);
        $token = $this->token($client, 'a@example.com');
        $admin = $this->token($client, 'admin@example.com');
        $uri = "/api/v1/links/{$link->getId()}/stats/summary?".self::PERIOD;
        $top = '/api/v1/admin/stats/top-links?'.self::PERIOD;

        $this->get($client, $token, $uri);
        self::assertSame(['doomed', 'stays'], array_column($this->get($client, $admin, $top)['items'], 'slug'));

        $this->api($client, $token, 'DELETE', '/api/v1/links/'.$link->getId());
        self::assertResponseStatusCodeSame(204);

        $this->api($client, $token, 'GET', $uri);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['stays'], array_column($this->get($client, $admin, $top)['items'], 'slug'));
    }

    public function testCacheStoreUnavailableStillAnswersWithDatabaseNumbers(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        self::click($link->getId(), '2026-09-02T10:00:00Z', [], 3);
        $log = $this->breakTheCache();
        $token = $this->token($client, 'a@example.com');

        $body = $this->get($client, $token, "/api/v1/links/{$link->getId()}/stats/summary?".self::PERIOD);

        self::assertSame(3, $body['clicksInPeriod']);
        $warnings = self::warnings($log);
        self::assertNotSame([], $warnings, 'the adapter logs the refused connection');
        self::assertStringStartsWith('Failed to fetch key', $warnings[0]->message);
        self::assertInstanceOf(\Exception::class, $warnings[0]->context['exception']); // the failure class travels in the record
        self::assertStringContainsString('Connection refused', (string) $warnings[0]->context['exception']->getMessage());
    }

    public function testCacheStoreUnavailableDuringAPatch(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a]);
        $log = $this->breakTheCache();
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['isActive' => false]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->decode($client)['isActive']);
        $messages = array_map(static fn ($r): string => $r->message, self::warnings($log));
        self::assertContains('Report cache not invalidated', $messages, implode(' | ', $messages)); // ours, after the adapter's own 'Failed to invalidate tags'
        $ours = array_values(array_filter(self::warnings($log), static fn ($r): bool => 'Report cache not invalidated' === $r->message))[0];
        self::assertSame((string) $link->getId(), $ours->context['link_id']);
    }

    /** A tag-aware Redis pool on a port nobody listens on, installed before the first request. */
    private function breakTheCache(): TestHandler
    {
        $log = new TestHandler();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($log);
        $broken = new RedisTagAwareAdapter(RedisAdapter::createConnection('redis://127.0.0.1:1?lazy=1'));
        $psr = self::getContainer()->get(LoggerInterface::class);
        self::assertInstanceOf(LoggerInterface::class, $psr);
        $broken->setLogger($psr); // as the framework wires every pool
        self::getContainer()->set(ReportCache::class, new ReportCache($broken, $psr));

        return $log;
    }

    /**
     * @return list<\Monolog\LogRecord>
     */
    private static function warnings(TestHandler $log): array
    {
        return array_values(array_filter($log->getRecords(), static fn ($r): bool => Logger::WARNING === $r->level->value));
    }

    /**
     * @return list<string>
     */
    private static function clickStatements(): array
    {
        return array_values(array_filter(StatementRecorder::statements(), static fn (string $sql): bool => (bool) preg_match('/\bclicks\b/i', $sql)));
    }

    protected function get(KernelBrowser $client, string $token, string $uri, int $expected = 200): array
    {
        return parent::get($client, $token, $uri, $expected);
    }
}
