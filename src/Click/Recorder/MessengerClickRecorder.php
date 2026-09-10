<?php

declare(strict_types=1);

namespace App\Click\Recorder;

use App\Click\ClickFacts;
use App\Click\ClickRecorderInterface;
use App\Click\Counter\ClickCounterInterface;
use App\Click\Message\ClickRecorded;
use App\Click\RecordOutcome;
use App\Click\RefererHost;
use App\Click\Visit;
use App\Click\VisitorHasher;
use App\Link\Entity\Link;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The asynchronous click write path (design decision 1), behind the seam the
 * redirect already uses. A link with max_clicks consults the counter first —
 * exhausted → nothing is dispatched; a counter failure PROPAGATES, and the
 * resolver answers 503 for exactly this case. Then one ClickRecorded message
 * is dispatched; a dispatch failure is caught HERE (error record, ids and the
 * exception class only) and the redirect is still served (FR-RED-4): the limit
 * was already enforced, only the record is lost. No SQL on this path.
 */
final readonly class MessengerClickRecorder implements ClickRecorderInterface
{
    private \Closure $clickIdFactory;

    /**
     * @param (\Closure(): Uuid)|null $clickIdFactory test seam; production ids are UUID v7
     */
    public function __construct(
        private ClickCounterInterface $counter,
        private MessageBusInterface $bus,
        private VisitorHasher $hasher,
        private RefererHost $refererHost,
        private LoggerInterface $logger,
        ?\Closure $clickIdFactory = null,
    ) {
        $this->clickIdFactory = $clickIdFactory ?? static fn (): Uuid => Uuid::v7();
    }

    public function record(Link $link, Visit $visit, ClickFacts $facts): RecordOutcome
    {
        $max = $link->getMaxClicks();
        if (null !== $max && RecordOutcome::Exhausted === $this->counter->increment($link->getId(), $link->getClickCount(), $max)) {
            return RecordOutcome::Exhausted;
        }

        $message = new ClickRecorded(
            ($this->clickIdFactory)()->toRfc4122(),
            $link->getId()->toRfc4122(),
            $visit->occurredAt,
            $facts->country,
            $facts->deviceType,
            $facts->os,
            $facts->browser,
            $facts->isBot,
            $facts->resolvedBy,
            $facts->variant,
            $this->refererHost->of($visit->referer),
            $this->hasher->hash($visit),
        );
        try {
            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            // the limit is already enforced; only the record is lost (spec redirect, "Failures of the stores")
            $this->logger->error('Click message not dispatched; redirect served anyway', ['link_id' => (string) $link->getId(), 'exception' => $e::class]);
        }

        return RecordOutcome::Allowed;
    }
}
