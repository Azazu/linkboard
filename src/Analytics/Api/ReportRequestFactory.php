<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Analytics\Report\Granularity;
use App\Analytics\Report\InvalidReportParameter;
use App\Analytics\Report\Period;
use App\Analytics\Report\ReportRequest;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The effective report parameters from the validated query string (design
 * decisions 3 and 4): single values were already checked by the declared
 * parameters; the cross-parameter rules (order, length, hourly bound) are
 * applied here and rendered as the same 422 with the parameter's name. One
 * clock reading per request — the default period, "today" and `generatedAt`
 * never disagree.
 */
final readonly class ReportRequestFactory
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * @param bool $withPeriod false for a report without a period (the admin summary): `from`/`to` are ignored and the default period stands
     */
    public function fromRequest(?Request $request, ?Uuid $linkId, bool $withGranularity = false, bool $withLimit = false, bool $withPeriod = true): ReportRequest
    {
        $query = null === $request ? new \Symfony\Component\HttpFoundation\InputBag() : $request->query;
        try {
            $period = $withPeriod ? Period::of($query->get('from'), $query->get('to'), $this->clock) : Period::defaults($this->clock);
            $granularity = Granularity::Day;
            if ($withGranularity && $query->has('granularity')) {
                $granularity = Granularity::tryFrom((string) $query->get('granularity')) ?? throw new InvalidReportParameter('granularity', 'granularity must be hour or day.');
            }
            $limit = ReportRequest::DEFAULT_LIMIT;
            if ($withLimit && $query->has('limit')) {
                $raw = (string) $query->get('limit');
                if (!ctype_digit($raw)) {
                    throw new InvalidReportParameter('limit', 'limit must be an integer.');
                }
                $limit = (int) $raw;
            }
            $includeBots = false;
            if ($query->has('includeBots')) {
                $includeBots = filter_var($query->get('includeBots'), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? throw new InvalidReportParameter('includeBots', 'includeBots must be true or false.');
            }

            return new ReportRequest($linkId, $period, $granularity, $limit, $includeBots);
        } catch (InvalidReportParameter $e) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($e->getMessage(), null, [], null, $e->parameter, $query->get($e->parameter))]));
        }
    }

    /** The moment the report is computed, in UTC. */
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }

    public function startOfToday(): \DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }
}
