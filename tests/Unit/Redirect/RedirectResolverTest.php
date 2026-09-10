<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect;

use App\Auth\Entity\User;
use App\Click\ClickFacts;
use App\Click\ClickRecorderInterface;
use App\Click\RecordOutcome;
use App\Click\Visit;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Link\Rules\RulesDocumentParser;
use App\Redirect\Detection\DetectedClient;
use App\Redirect\Detection\DeviceDetectionInterface;
use App\Redirect\Geo\CountryResolverInterface;
use App\Redirect\RedirectDecision;
use App\Redirect\RedirectResolver;
use App\Redirect\RedirectStatus;
use App\Redirect\Rules\RuleEvaluator;
use App\Redirect\VisitFactory;
use App\Redirect\VisitorProfiler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Spec redirect: "Response matrix", HEAD scenario, "Failures of the stores";
 * spec routing-rules: "Hostile input and failures degrade to the default
 * target" at the resolver level (design decisions 2, 5, 6, 13).
 */
#[CoversClass(RedirectResolver::class)]
#[CoversClass(RedirectDecision::class)]
#[CoversClass(RedirectStatus::class)]
final class RedirectResolverTest extends TestCase
{
    private const array RULES = ['version' => 1, 'rules' => [['match' => ['device' => ['smartphone']], 'target' => 'https://m.example.com/']], 'variants' => [['name' => 'A', 'weight' => 50, 'target' => 'https://example.com/a'], ['name' => 'B', 'weight' => 50, 'target' => 'https://example.com/b']]];

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

    public function testHeadResolvesTheRuleTargetWithoutRecording(): void
    {
        $link = $this->link(utm: ['utm_source' => 'x'], rules: self::RULES);
        $resolver = $this->resolver($link, $this->recorderNeverCalled(), $this->detecting('smartphone', 'iOS'));

        $decision = $resolver->resolve('s', $this->visit(isHead: true));

        self::assertSame(RedirectStatus::Redirect, $decision->status);
        self::assertSame('https://m.example.com/?utm_source=x', $decision->location);
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

    public function testMatchingRuleReachesTheRecorderAsFacts(): void
    {
        $link = $this->link(rules: self::RULES);
        $recorder = $this->createMock(ClickRecorderInterface::class);
        $recorder->expects(self::once())->method('record')
            ->with(self::anything(), self::anything(), self::callback(static fn (ClickFacts $f): bool => 'device' === $f->resolvedBy && null === $f->variant && 'smartphone' === $f->deviceType && 'iOS' === $f->os && 'Mobile Safari' === $f->browser && 'DE' === $f->country))
            ->willReturn(RecordOutcome::Allowed);

        $decision = $this->resolver($link, $recorder, $this->detecting('smartphone', 'iOS'), 'DE')->resolve('s', $this->visit());

        self::assertSame('https://m.example.com/', $decision->location);
        self::assertCount(0, $this->log->getRecords());
    }

    public function testHostileVisitTakesTheDefaultTargetWithOneNoticeAndNoProfiling(): void
    {
        $link = $this->link(utm: ['utm_source' => 'x'], rules: self::RULES);
        $detection = $this->createMock(DeviceDetectionInterface::class);
        $detection->expects(self::never())->method('detect');
        $recorder = $this->createMock(ClickRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with(self::anything(), self::anything(), self::equalTo(ClickFacts::default()))->willReturn(RecordOutcome::Allowed);
        $visit = new Visit('203.0.113.7', 'Probe/1.0', 'https://ref.example.org/', new \DateTimeImmutable(), inputIssues: [VisitFactory::ISSUE_USER_AGENT_OVERSIZED, VisitFactory::ISSUE_ACCEPT_LANGUAGE_MALFORMED]);

        $decision = $this->resolver($link, $recorder, $detection)->resolve('s', $visit);

        self::assertSame('https://example.com/t?utm_source=x', $decision->location);
        self::assertCount(1, $this->log->getRecords());
        $record = $this->log->getRecords()[0];
        self::assertSame(Logger::NOTICE, $record->level->value);
        self::assertSame((string) $link->getId(), $record->context['link_id']);
        self::assertSame([VisitFactory::ISSUE_USER_AGENT_OVERSIZED, VisitFactory::ISSUE_ACCEPT_LANGUAGE_MALFORMED], $record->context['issues']);
        $this->assertNoPersonalData(json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    public function testProfilerFailureTakesTheDefaultTargetWithOneNotice(): void
    {
        $link = $this->link(rules: self::RULES);
        $detection = $this->createStub(DeviceDetectionInterface::class);
        $detection->method('detect')->willThrowException(new \RuntimeException('regex database corrupt'));
        $recorder = $this->createMock(ClickRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with(self::anything(), self::anything(), self::equalTo(ClickFacts::default()))->willReturn(RecordOutcome::Allowed);

        $decision = $this->resolver($link, $recorder, $detection)->resolve('s', $this->visit());

        self::assertSame('https://example.com/t', $decision->location);
        self::assertCount(1, $this->log->getRecords());
        $record = $this->log->getRecords()[0];
        self::assertSame(Logger::NOTICE, $record->level->value);
        self::assertSame((string) $link->getId(), $record->context['link_id']);
        self::assertSame(\RuntimeException::class, $record->context['exception']);
        $this->assertNoPersonalData(json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    public function testUnusableStoredRulesTakeTheDefaultTargetWithOneNotice(): void
    {
        $link = $this->link(rules: ['version' => 2, 'rules' => 'broken']);
        $recorder = $this->createMock(ClickRecorderInterface::class);
        $recorder->expects(self::once())->method('record')->with(self::anything(), self::anything(), self::equalTo(ClickFacts::default()))->willReturn(RecordOutcome::Allowed);

        $decision = $this->resolver($link, $recorder)->resolve('s', $this->visit());

        self::assertSame('https://example.com/t', $decision->location);
        self::assertCount(1, $this->log->getRecords());
        self::assertSame(Logger::NOTICE, $this->log->getRecords()[0]->level->value);
        self::assertSame(\App\Redirect\Rules\UnusableRulesException::class, $this->log->getRecords()[0]->context['exception']);
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
        $resolver = new RedirectResolver($links, $this->recorderNeverCalled(), $this->profiler(), new RuleEvaluator(new RulesDocumentParser()), new Logger('test', [$this->log]));

        $decision = $resolver->resolve('secret-slug', $this->visit());

        self::assertSame(RedirectStatus::Unavailable, $decision->status);
        self::assertTrue($this->log->hasErrorRecords());
        $record = $this->log->getRecords()[0];
        self::assertSame(11, $record->context['slug_length']);
        self::assertStringNotContainsString('secret-slug', json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    private function resolver(?Link $link, ClickRecorderInterface $recorder, ?DeviceDetectionInterface $detection = null, ?string $country = null): RedirectResolver
    {
        $links = $this->createStub(LinkRepositoryInterface::class);
        $links->method('findBySlug')->willReturn($link);

        return new RedirectResolver($links, $recorder, $this->profiler($detection, $country), new RuleEvaluator(new RulesDocumentParser()), new Logger('test', [$this->log]));
    }

    private function profiler(?DeviceDetectionInterface $detection = null, ?string $country = null): VisitorProfiler
    {
        if (null === $detection) {
            $detection = $this->createStub(DeviceDetectionInterface::class);
            $detection->method('detect')->willReturn(DetectedClient::unknown());
        }
        $countries = $this->createStub(CountryResolverInterface::class);
        $countries->method('resolve')->willReturn($country);

        return new VisitorProfiler($detection, $countries);
    }

    private function detecting(string $device, string $os): DeviceDetectionInterface
    {
        $detection = $this->createStub(DeviceDetectionInterface::class);
        $detection->method('detect')->willReturn(new DetectedClient($device, $os, 'Mobile Safari', false));

        return $detection;
    }

    /**
     * @param array<string, string>|null $utm
     * @param array<string, mixed>|null  $rules
     */
    private function link(?int $maxClicks = null, int $clickCount = 0, ?array $utm = null, ?array $rules = null): Link
    {
        $now = new \DateTimeImmutable();
        $link = new Link(new User('o@example.com', 'hash', $now), 's', 'https://example.com/t', $now);
        if (null !== $maxClicks) {
            $link->setClickLimit($maxClicks, $now);
        }
        if (null !== $utm) {
            $link->replaceUtm($utm, $now);
        }
        if (null !== $rules) {
            $link->replaceRules($rules, $now);
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
