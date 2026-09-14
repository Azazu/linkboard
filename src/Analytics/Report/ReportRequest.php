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

    /**
     * The same request reduced to the parameters one report actually uses.
     *
     * The cache key digests the period, the granularity and the limit for
     * every report, so a caller with one set of controls feeding several
     * reports — a statistics page — would otherwise write keys no other
     * caller produces. Each report reduces the request first, which is why
     * this sits beside `cacheKey()`: the two have to agree.
     *
     * @param ?Period $period the period to use instead, for a report that has none of its own
     */
    public function reducedTo(bool $withGranularity, bool $withLimit, ?Period $period = null): self
    {
        return new self(
            $this->linkId,
            $period ?? $this->period,
            $withGranularity ? $this->granularity : Granularity::Day,
            $withLimit ? $this->limit : self::DEFAULT_LIMIT,
            $this->includeBots,
        );
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
