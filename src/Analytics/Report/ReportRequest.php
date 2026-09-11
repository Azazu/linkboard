<?php

declare(strict_types=1);

namespace App\Analytics\Report;

use Symfony\Component\Uid\Uuid;

/**
 * The effective parameters of one report — a link (null for the global
 * statistics), the period, the bucket size, the top-N limit and the bot flag —
 * and the cache key they define (design decision 7).
 */
final readonly class ReportRequest
{
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 50;

    public function __construct(
        public ?Uuid $linkId,
        public Period $period,
        public Granularity $granularity = Granularity::Day,
        public int $limit = self::DEFAULT_LIMIT,
        public bool $includeBots = false,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidReportParameter('limit', \sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }
        $granularity->assertAllowedFor($period);
    }

    /** A PSR-6 safe key: the report name and a digest of every effective parameter. */
    public function cacheKey(string $report): string
    {
        return $report.'.'.sha1(implode('|', [
            null === $this->linkId ? 'global' : $this->linkId->toRfc4122(),
            $this->period->from->format(\DateTimeInterface::ATOM),
            $this->period->to->format(\DateTimeInterface::ATOM),
            $this->granularity->value,
            (string) $this->limit,
            $this->includeBots ? '1' : '0',
        ]));
    }

    public function cacheTag(): string
    {
        return null === $this->linkId ? 'global' : 'link-'.$this->linkId->toRfc4122();
    }
}
