<?php

declare(strict_types=1);

namespace App\Analytics\Report;

use Symfony\Component\Clock\ClockInterface;

/**
 * The half-open UTC period of a report, `from` inclusive and `to` exclusive
 * (design decision 1). Defaults are day-aligned — the current UTC day and the
 * 29 before it — so identical default requests share one cache entry for a
 * whole day instead of missing on a moving "now".
 */
final readonly class Period
{
    public const int MAX_DAYS = 366;
    public const int DEFAULT_DAYS = 30;

    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }

    public static function defaults(ClockInterface $clock): self
    {
        $to = self::startOfNextUtcDay($clock);

        return new self($to->modify(\sprintf('-%d days', self::DEFAULT_DAYS)), $to);
    }

    /**
     * @throws InvalidReportParameter when a bound is not RFC 3339, `from` is not before `to`, or the period exceeds MAX_DAYS
     */
    public static function of(mixed $from, mixed $to, ClockInterface $clock): self
    {
        $defaultTo = self::startOfNextUtcDay($clock);
        $toDate = null === $to ? $defaultTo : self::parse('to', $to);
        $fromDate = null === $from ? $toDate->modify(\sprintf('-%d days', self::DEFAULT_DAYS)) : self::parse('from', $from);

        if ($fromDate >= $toDate) {
            throw new InvalidReportParameter('from', 'from must be before to.');
        }
        if ($fromDate->modify(\sprintf('+%d days', self::MAX_DAYS)) < $toDate) {
            throw new InvalidReportParameter('to', \sprintf('The period must not exceed %d days.', self::MAX_DAYS));
        }

        return new self($fromDate, $toDate);
    }

    /** The period of the same length that ends where this one starts. */
    public function previous(): self
    {
        $length = $this->to->getTimestamp() - $this->from->getTimestamp();

        return new self($this->from->modify(\sprintf('-%d seconds', $length)), $this->from);
    }

    /** Length in whole or fractional days. */
    public function days(): float
    {
        return ($this->to->getTimestamp() - $this->from->getTimestamp()) / 86400;
    }

    private static function startOfNextUtcDay(ClockInterface $clock): \DateTimeImmutable
    {
        return $clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0)->modify('+1 day');
    }

    private static function parse(string $parameter, mixed $value): \DateTimeImmutable
    {
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $value) : false;
        if (false === $date) {
            throw new InvalidReportParameter($parameter, \sprintf('%s must be an RFC 3339 timestamp, for example 2026-09-01T00:00:00Z.', $parameter));
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
