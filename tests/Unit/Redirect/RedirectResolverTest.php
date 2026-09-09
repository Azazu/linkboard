<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect;

use App\Auth\Entity\User;
use App\Click\ClickRecorderInterface;
use App\Click\RecordOutcome;
use App\Click\Visit;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Redirect\RedirectDecision;
use App\Redirect\RedirectResolver;
use App\Redirect\RedirectStatus;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Spec redirect: "Response matrix", HEAD scenario, "Failures of the stores" (design decisions 2, 5, 13). */
#[CoversClass(RedirectResolver::class)]
#[CoversClass(RedirectDecision::class)]
#[CoversClass(RedirectStatus::class)]
final class RedirectResolverTest extends TestCase
{
    private TestHandler $log;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
    }

    public function testUnknownSlugIsNotFound(): void
    {
        $resolver = $this->resolver(null, $this->recorderNeverCalled());

        self::assertSame(RedirectStatus::NotFound, $resolver->resolve('nothing', $this->visit())->status);
    }

    public function testInactiveLinkIsNotFoundEvenWhenExpired(): void
    {
        $link = $this->link();
        $link->setExpiry(new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable());
        $link->deactivate(new \DateTimeImmutable());
        $resolver = $this->resolver($link, $this->recorderNeverCalled());

        self::assertSame(RedirectStatus::NotFound, $resolver->resolve('s', $this->visit('+2 hours'))->status);
    }

    public function testExpiredLinkIsGoneWithoutRecording(): void
    {
        $link = $this->link();
        $link->setExpiry(new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable());
        self::assertSame(RedirectStatus::Gone, $this->resolver($link, $this->recorderNeverCalled())->resolve('s', $this->visit('+1 hour'))->status, 'expiry is inclusive');

        $recorder = $this->createStub(ClickRecorderInterface::class);
        $recorder->method('record')->willReturn(RecordOutcome::Allowed);
        self::assertSame(RedirectStatus::Redirect, $this->resolver($link, $recorder)->resolve('s', $this->visit('+59 minutes'))->status);
    }

    public function testExhaustedLinkIsGoneWithoutRecording(): void
    {
        $link = $this->link(maxClicks: 3, clickCount: 3);
        $resolver = $this->resolver($link, $this->recorderNeverCalled());

        self::assertSame(RedirectStatus::Gone, $resolver->resolve('s', $this->visit())->status);
    }

    public function testHeadRedirectsWithoutRecording(): void
    {
        $link = $this->link(utm: ['utm_source' => 'x']);
        $resolver = $this->resolver($link, $this->recorderNeverCalled());

        $decision = $resolver->resolve('s', $this->visit(isHead: true));

        self::assertSame(RedirectStatus::Redirect, $decision->status);
        self::assertSame('https://example.com/t?utm_source=x', $decision->location);
    }

    public function testRecordedClickRedirectsWithUtm(): void
    {
        $link = $this->link(maxClicks: 3, clickCount: 2, utm: ['utm_source' => 'x']);
        $recorder = $this->createMock(ClickRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->willReturn(RecordOutcome::Allowed);

        $decision = $this->resolver($link, $recorder)->resolve('s', $this->visit());

        self::assertSame(RedirectStatus::Redirect, $decision->status);
        self::assertSame('https://example.com/t?utm_source=x', $decision->location);
    }

    public function testRecorderExhaustedIsGone(): void
    {
        $recorder = $this->createStub(ClickRecorderInterface::class);
        $recorder->method('record')->willReturn(RecordOutcome::Exhausted);

        self::assertSame(RedirectStatus::Gone, $this->resolver($this->link(maxClicks: 3), $recorder)->resolve('s', $this->visit())->status);
    }

    public function testWriteFailureOnAnUnlimitedLinkStillRedirectsAndLogsAnError(): void
    {
        $link = $this->link();
        $decision = $this->resolver($link, $this->recorderThrowing())->resolve('s', $this->visit());

        self::assertSame(RedirectStatus::Redirect, $decision->status);
        self::assertTrue($this->log->hasErrorRecords());
        $record = $this->log->getRecords()[0];
        self::assertSame((string) $link->getId(), $record->context['link_id']);
        self::assertSame(\RuntimeException::class, $record->context['exception']);
        $this->assertNoPersonalData(json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    public function testWriteFailureOnALimitedLinkIsUnavailableAndLogsAWarning(): void
    {
        $decision = $this->resolver($this->link(maxClicks: 10), $this->recorderThrowing())->resolve('s', $this->visit());

        self::assertSame(RedirectStatus::Unavailable, $decision->status);
        self::assertTrue($this->log->hasWarningRecords());
        self::assertFalse($this->log->hasErrorRecords());
        $this->assertNoPersonalData(json_encode($this->log->getRecords()[0]->toArray(), \JSON_THROW_ON_ERROR));
    }

    public function testLookupFailureIsUnavailableAndLogsWithoutTheSlug(): void
    {
        $links = $this->createStub(LinkRepositoryInterface::class);
        $links->method('findBySlug')->willThrowException(new \RuntimeException('connection refused'));
        $resolver = new RedirectResolver($links, $this->recorderNeverCalled(), new Logger('test', [$this->log]));

        $decision = $resolver->resolve('secret-slug', $this->visit());

        self::assertSame(RedirectStatus::Unavailable, $decision->status);
        self::assertTrue($this->log->hasErrorRecords());
        $record = $this->log->getRecords()[0];
        self::assertSame(11, $record->context['slug_length']);
        self::assertStringNotContainsString('secret-slug', json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    private function resolver(?Link $link, ClickRecorderInterface $recorder): RedirectResolver
    {
        $links = $this->createStub(LinkRepositoryInterface::class);
        $links->method('findBySlug')->willReturn($link);

        return new RedirectResolver($links, $recorder, new Logger('test', [$this->log]));
    }

    /** @param array<string, string>|null $utm */
    private function link(?int $maxClicks = null, int $clickCount = 0, ?array $utm = null): Link
    {
        $now = new \DateTimeImmutable();
        $link = new Link(new User('o@example.com', 'hash', $now), 's', 'https://example.com/t', $now);
        if (null !== $maxClicks) {
            $link->setClickLimit($maxClicks, $now);
        }
        if (null !== $utm) {
            $link->replaceUtm($utm, $now);
        }
        if ($clickCount > 0) {
            // click_count is written by SQL only (design decision 4); the entity has no setter
            $property = new \ReflectionProperty(Link::class, 'clickCount');
            $property->setValue($link, $clickCount);
        }

        return $link;
    }

    private function visit(string $at = 'now', bool $isHead = false): Visit
    {
        return new Visit('203.0.113.7', 'Probe/1.0', 'https://ref.example.org/', new \DateTimeImmutable($at), $isHead);
    }

    private function recorderNeverCalled(): ClickRecorderInterface
    {
        $recorder = $this->createMock(ClickRecorderInterface::class);
        $recorder->expects(self::never())->method('record');

        return $recorder;
    }

    private function recorderThrowing(): ClickRecorderInterface
    {
        $recorder = $this->createStub(ClickRecorderInterface::class);
        $recorder->method('record')->willThrowException(new \RuntimeException('down'));

        return $recorder;
    }

    private function assertNoPersonalData(string $json): void
    {
        self::assertStringNotContainsString('203.0.113.7', $json);
        self::assertStringNotContainsString('Probe/1.0', $json);
        self::assertStringNotContainsString('ref.example.org', $json);
        self::assertStringNotContainsString('example.com/t', $json);
    }
}
