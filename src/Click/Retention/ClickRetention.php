<?php

declare(strict_types=1);

namespace App\Click\Retention;

use App\Shared\Boot\StartupCheckInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The retention policy of the click records: how far back the schema keeps
 * partitions, how far forward it provisions them, and how far back data has
 * actually been removed (change stretch-partition-clicks, design decisions 5
 * and 5b).
 *
 * The expiry boundary is the later of the configured window and the recorded
 * removal point. The record exists because the window is configuration and can
 * move: drop July under a one-month window, widen the window to thirteen, and
 * the provisioning recreates July — a replayed message would then pass a guard
 * that only knew the window, be recorded a second time, and increment the
 * link's lifetime counter twice.
 */
final readonly class ClickRetention implements StartupCheckInterface
{
    /** The defaults the migration's initial provisioning also uses. */
    public const int DEFAULT_MONTHS = 13;
    public const int DEFAULT_HORIZON_MONTHS = 3;

    public function __construct(
        private Connection $connection,
        #[Autowire(env: 'CLICK_RETENTION_MONTHS')]
        private string $months = '',
        #[Autowire(env: 'CLICK_PARTITION_HORIZON_MONTHS')]
        private string $horizonMonths = '',
    ) {
    }

    /**
     * Both settings are verified on every boot, so nothing downstream has to
     * decide what to do with a window it cannot parse — the application refuses
     * to start instead, the way the country-resolver chain does.
     */
    public function check(): void
    {
        $this->months();
        $this->horizonMonths();
    }

    /**
     * Whether a click this old is behind the boundary and must not be recorded.
     *
     * The first comparison is what keeps this off the hot path: a valid window
     * is at least one month, so a boundary can never be newer than one month
     * ago, and a click from the last month — which is every click the worker
     * normally sees — is answered without reading the table at all.
     */
    public function isExpired(\DateTimeImmutable $occurredAt, \DateTimeImmutable $now): bool
    {
        if ($occurredAt > $now->modify('-1 month')) {
            return false;
        }

        return $occurredAt < $this->expiryBoundary($now);
    }

    /**
     * The configured window, in whole months.
     *
     * @throws InvalidRetentionSetting when the value is not a whole number of months of at least one
     */
    public function months(): int
    {
        return self::wholeMonths($this->months, 'CLICK_RETENTION_MONTHS');
    }

    /**
     * How many months ahead partitions are provisioned.
     *
     * @throws InvalidRetentionSetting when the value is not a whole number of months of at least one
     */
    public function horizonMonths(): int
    {
        return self::wholeMonths($this->horizonMonths, 'CLICK_PARTITION_HORIZON_MONTHS');
    }

    /**
     * The first instant a click may still be recorded for: the later of the
     * configured window's start and the point data has been removed through.
     */
    public function expiryBoundary(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $fromWindow = $now->modify(\sprintf('-%d months', $this->months()));
        $removed = $this->droppedThrough();

        return null === $removed || $removed < $fromWindow ? $fromWindow : $removed;
    }

    /**
     * The instant click data has been removed through, or null when no
     * partition has ever been dropped.
     */
    public function droppedThrough(): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne('SELECT dropped_through FROM clicks_retention');
        if (false === $value || null === $value) {
            return null;
        }

        return new \DateTimeImmutable(\is_string($value) ? $value : throw new \UnexpectedValueException('clicks_retention.dropped_through is not a timestamp.'));
    }

    /**
     * Records that data has been removed through this instant. Never moves the
     * record backwards: a shorter window later does not un-remove anything, and
     * a longer one must not make a removed click recordable again.
     */
    public function recordDroppedThrough(\DateTimeImmutable $through): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO clicks_retention (singleton, dropped_through) VALUES (true, :through)
                ON CONFLICT (singleton) DO UPDATE SET dropped_through = GREATEST(clicks_retention.dropped_through, EXCLUDED.dropped_through)
                SQL,
            ['through' => $through],
            ['through' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    private static function wholeMonths(string $value, string $name): int
    {
        if (1 !== preg_match('/^\d+$/', $value) || (int) $value < 1) {
            throw new InvalidRetentionSetting($name, $value);
        }

        return (int) $value;
    }
}
