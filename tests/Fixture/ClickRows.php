<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

/**
 * Click rows for the analytics tests, inserted through the DBAL with the
 * handler's twelve columns. `visitor` is a label hashed like the real
 * visitor_hash (64 hex characters), so two rows with the same label are one
 * visitor; `visitorHash` stores the given 64-hex string as is (for rows whose
 * hashes must relate in a chosen way, e.g. a shared prefix).
 */
final class ClickRows
{
    private function __construct()
    {
    }

    /**
     * @param array{country?: ?string, deviceType?: ?string, os?: ?string, browser?: ?string, bot?: bool, referer?: ?string, visitor?: string, visitorHash?: string, variant?: ?string, resolvedBy?: string} $facts
     *
     * @return string the click id
     */
    public static function add(Connection $connection, Uuid $linkId, string $occurredAt, array $facts = []): string
    {
        $id = Uuid::v7()->toRfc4122();
        $connection->insert('clicks', [
            'id' => $id,
            'link_id' => $linkId->toRfc4122(),
            'occurred_at' => new \DateTimeImmutable($occurredAt),
            'country' => $facts['country'] ?? null,
            'device_type' => $facts['deviceType'] ?? null,
            'os' => $facts['os'] ?? null,
            'browser' => $facts['browser'] ?? null,
            'is_bot' => $facts['bot'] ?? false,
            'referer_host' => $facts['referer'] ?? null,
            'visitor_hash' => $facts['visitorHash'] ?? hash('sha256', $facts['visitor'] ?? $id),
            'variant' => $facts['variant'] ?? null,
            'resolved_by' => $facts['resolvedBy'] ?? (isset($facts['variant']) ? 'variant' : 'default'),
        ], ['occurred_at' => Types::DATETIMETZ_IMMUTABLE, 'is_bot' => Types::BOOLEAN]);

        return $id;
    }

    /**
     * @param array{country?: ?string, deviceType?: ?string, os?: ?string, browser?: ?string, bot?: bool, referer?: ?string, visitor?: string, visitorHash?: string, variant?: ?string, resolvedBy?: string} $facts
     */
    public static function many(Connection $connection, Uuid $linkId, int $count, string $occurredAt, array $facts = []): void
    {
        for ($i = 0; $i < $count; ++$i) {
            self::add($connection, $linkId, $occurredAt, $facts);
        }
    }
}
