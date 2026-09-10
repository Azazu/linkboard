<?php

declare(strict_types=1);

namespace App\Click\Recorder;

use App\Click\ClickFacts;
use App\Click\ClickRecorderInterface;
use App\Click\RecordOutcome;
use App\Click\RefererHost;
use App\Click\Visit;
use App\Click\VisitorHasher;
use App\Link\Entity\Link;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * Synchronous baseline of the click write path (design decision 3): one
 * transaction with a conditional UPDATE — the check-and-increment of the
 * click limit — followed by the INSERT of the click row. The row lock taken
 * by the UPDATE serialises concurrent redirects, so the count never passes
 * max_clicks; 0 affected rows means the limit was reached and nothing is
 * stored. No entity is hydrated and links.click_count is written by SQL only.
 */
final readonly class DbalClickRecorder implements ClickRecorderInterface
{
    private \Closure $clickIdFactory;

    /**
     * @param (\Closure(): Uuid)|null $clickIdFactory test seam; production ids are UUID v7
     */
    public function __construct(
        private Connection $connection,
        private VisitorHasher $hasher,
        private RefererHost $refererHost,
        ?\Closure $clickIdFactory = null,
    ) {
        $this->clickIdFactory = $clickIdFactory ?? static fn (): Uuid => Uuid::v7();
    }

    public function record(Link $link, Visit $visit, ClickFacts $facts): RecordOutcome
    {
        return $this->connection->transactional(function (Connection $connection) use ($link, $visit, $facts): RecordOutcome {
            $affected = $connection->executeStatement(
                'UPDATE links SET click_count = click_count + 1 WHERE id = :id AND (max_clicks IS NULL OR click_count < max_clicks)',
                ['id' => $link->getId()->toRfc4122()],
            );
            if (0 === $affected) {
                return RecordOutcome::Exhausted;
            }

            $connection->insert('clicks', [
                'id' => ($this->clickIdFactory)()->toRfc4122(),
                'link_id' => $link->getId()->toRfc4122(),
                'occurred_at' => $visit->occurredAt,
                'country' => $facts->country,
                'device_type' => $facts->deviceType,
                'os' => $facts->os,
                'browser' => $facts->browser,
                'is_bot' => $facts->isBot,
                'referer_host' => $this->refererHost->of($visit->referer),
                'visitor_hash' => $this->hasher->hash($visit),
                'variant' => $facts->variant,
                'resolved_by' => $facts->resolvedBy,
            ], [
                'occurred_at' => Types::DATETIMETZ_IMMUTABLE,
                'is_bot' => Types::BOOLEAN,
            ]);

            return RecordOutcome::Allowed;
        });
    }
}
