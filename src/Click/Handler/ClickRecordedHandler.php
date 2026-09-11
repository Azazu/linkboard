<?php

declare(strict_types=1);

namespace App\Click\Handler;

use App\Click\Message\ClickRecorded;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * FR-CLK-2…5 (design decision 4): one transaction — INSERT the click row, then
 * increment links.click_count. The insert goes first so the constraints decide
 * the outcome before any increment, and both constraint failures are caught
 * OUTSIDE the rolled-back transaction: a duplicate click_id (redelivery, manual
 * retry) is acknowledged with nothing written; a missing link (deleted after the
 * redirect) is acknowledged and discarded with an info record — returning
 * normally, because an unrecoverable exception would still be parked on the
 * failed transport by Messenger's failure listener. Anything else propagates to
 * the retry strategy and, after the last retry, to `failed`. No entities: the
 * write path never hydrates (CQRS-lite).
 */
#[AsMessageHandler]
final readonly class ClickRecordedHandler
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ClickRecorded $message): void
    {
        try {
            $this->connection->transactional(static function (Connection $connection) use ($message): void {
                $connection->insert('clicks', [
                    'id' => $message->clickId,
                    'link_id' => $message->linkId,
                    'occurred_at' => $message->occurredAt,
                    'country' => $message->country,
                    'device_type' => $message->deviceType,
                    'os' => $message->os,
                    'browser' => $message->browser,
                    'is_bot' => $message->isBot,
                    'referer_host' => $message->refererHost,
                    'visitor_hash' => $message->visitorHash,
                    'variant' => $message->variant,
                    'resolved_by' => $message->resolvedBy,
                ], [
                    'occurred_at' => Types::DATETIMETZ_IMMUTABLE,
                    'is_bot' => Types::BOOLEAN,
                ]);
                $connection->executeStatement('UPDATE links SET click_count = click_count + 1 WHERE id = :id', ['id' => $message->linkId]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug('Click message redelivered; the click is already recorded', ['click_id' => $message->clickId]);
        } catch (ForeignKeyConstraintViolationException) {
            $this->logger->info('Click message discarded: the link no longer exists', ['link_id' => $message->linkId, 'click_id' => $message->clickId]);
        }
    }
}
