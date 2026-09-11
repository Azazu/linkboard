<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Click\Counter\RedisClickCounter;
use App\Click\Message\ClickRecorded;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixture\CountingClickCounter;
use App\Tests\Fixture\StatementRecorder;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec redirect "The redirect performs no database write"; spec click-logging
 * "One click per successful redirect" (eventual), "The queue carries no raw
 * personal data"; spec links "Click count catches up" — over HTTP with the
 * in-memory transport consumed by a real Worker.
 */
#[CoversNothing]
final class AsyncClickTest extends RedirectWebTestCase
{
    public function testOneRedirectIsOneMessageThenOneClickAndOneIncrement(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'promo-1']);

        self::visit($client, '/promo-1');
        self::assertResponseStatusCodeSame(302);

        // before the worker runs
        self::assertCount(0, self::rawClicksOf($link->getId()));
        self::assertSame(0, self::rawClickCountOf($link->getId()));
        self::assertCount(1, self::pendingMessages());
        $token = $this->token($client, 'a@example.com');
        self::assertSame(0, $this->apiGet($client, $token, '/api/v1/links/'.$link->getId())['clickCount']);

        // the worker runs
        self::assertSame(1, self::consumeAsync());
        self::assertCount(1, self::rawClicksOf($link->getId()));
        self::assertSame(1, $this->apiGet($client, $token, '/api/v1/links/'.$link->getId())['clickCount']);
        self::assertCount(0, self::pendingMessages());
        self::assertCount(1, self::acknowledgedMessages());
    }

    public function testClickCountCatchesUp(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'twice']);
        $token = $this->token($client, 'a@example.com');

        self::visit($client, '/twice');
        self::visit($client, '/twice');
        self::assertSame(0, $this->apiGet($client, $token, '/api/v1/links/'.$link->getId())['clickCount']);

        self::assertSame(2, self::consumeAsync());

        self::assertSame(2, $this->apiGet($client, $token, '/api/v1/links/'.$link->getId())['clickCount']);
    }

    public function testNonRedirectsDispatchNothing(): void
    {
        $client = self::createClient();
        LinkFactory::new()->inactive()->create(['slug' => 'inactive']);
        LinkFactory::new()->expiring(new \DateTimeImmutable('-1 minute'))->create(['slug' => 'expired']);
        LinkFactory::new()->limited(3, 3)->create(['slug' => 'exhausted']);

        foreach (['/nothing-here', '/inactive', '/expired', '/exhausted'] as $path) {
            self::visit($client, $path);
            self::assertContains($client->getResponse()->getStatusCode(), [404, 410]);
        }

        self::assertSame([], self::pendingMessages());
    }

    public function testTheMessageCarriesTheFactsAndNoPersonalData(): void
    {
        $client = self::createClient();
        LinkFactory::new()->limited(5)->create(['slug' => 'facts']);

        self::visit($client, '/facts', ['HTTP_REFERER' => 'https://News.Example.org/story?id=1']);

        $pending = self::pendingMessages();
        self::assertCount(1, $pending);
        $message = $pending[0]->getMessage();
        self::assertInstanceOf(ClickRecorded::class, $message);
        $serialized = serialize($message);
        self::assertSame('news.example.org', $message->refererHost);
        self::assertStringContainsString($message->visitorHash, $serialized);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $message->visitorHash);
        self::assertStringNotContainsString('203.0.113.7', $serialized);
        self::assertStringNotContainsString('Probe/1.0', $serialized);
        self::assertStringNotContainsString('story?id=1', $serialized);
        self::assertSame('default', $message->resolvedBy);
    }

    public function testTheRedirectPerformsNoDatabaseWrite(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $unlimited = LinkFactory::createOne(['slug' => 'free']);
        $limited = LinkFactory::new()->limited(5)->create(['slug' => 'capped']);
        $counting = new CountingClickCounter(self::counter());
        self::getContainer()->set(RedisClickCounter::class, $counting);

        StatementRecorder::reset();
        self::visit($client, '/free');
        self::assertResponseStatusCodeSame(302);
        $this->assertOneSelectAndNoWrite(StatementRecorder::statements());
        self::assertSame(0, $counting->increments, 'an unlimited link never touches Redis');

        StatementRecorder::reset();
        self::visit($client, '/capped');
        self::assertResponseStatusCodeSame(302);
        $this->assertOneSelectAndNoWrite(StatementRecorder::statements());
        self::assertSame(1, $counting->increments, 'one Redis command for a limited link');

        self::assertCount(2, self::pendingMessages());
        self::assertSame(2, self::consumeAsync());
        self::assertCount(1, self::rawClicksOf($unlimited->getId()));
        self::assertCount(1, self::rawClicksOf($limited->getId()));
    }

    public function testHeadIssuesNoCounterCommandAndNoMessage(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        LinkFactory::new()->limited(5)->create(['slug' => 'headed', 'targetUrl' => 'https://example.com/h']);
        $counting = new CountingClickCounter(self::counter());
        self::getContainer()->set(RedisClickCounter::class, $counting);

        self::visit($client, '/headed', method: 'HEAD');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/h');
        self::assertSame(0, $counting->increments);
        self::assertSame([], self::pendingMessages());
    }

    /** @param list<string> $statements */
    private function assertOneSelectAndNoWrite(array $statements): void
    {
        $kinds = array_map(static fn (string $sql): string => strtoupper(strtok(ltrim($sql), " \n\t(") ?: ''), $statements);
        $selects = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with(strtoupper(ltrim($sql)), 'SELECT')));
        self::assertCount(1, $selects, 'exactly one SELECT: '.implode(' | ', $statements));
        self::assertStringContainsString(' FROM links ', ' '.$selects[0].' ');
        self::assertSame([], array_intersect($kinds, ['INSERT', 'UPDATE', 'DELETE']), 'no SQL write on the hot path: '.implode(' | ', $statements));
    }
}
