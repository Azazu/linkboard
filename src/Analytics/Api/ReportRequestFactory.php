<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Analytics\Report\Granularity;
use App\Analytics\Report\InvalidReportParameter;
use App\Analytics\Report\Period;
use App\Analytics\Report\ReportRequest;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The effective report parameters from the values a caller supplied (design
 * decisions 3 and 4): single values were already checked by the declared
 * parameters; the cross-parameter rules (order, length, hourly bound) are
 * applied here and rendered as the same 422 with the parameter's name. One
 * clock reading per request — the default period, "today" and `generatedAt`
 * never disagree.
 *
 * The values arrive as a map, not as an HTTP request: a REST operation passes
 * its query string, the statistics page passes its form's values, and a
 * GraphQL resolver passes its arguments. It used to take a `?Request` and
 * tolerate `null` by falling back to defaults — through GraphQL, where there
 * is no request in the context, that tolerance would have answered every
 * report with the default period and told the caller nothing (change
 * stretch-graphql, design decision 2).
 */
final readonly class ReportRequestFactory
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * @param array<string, mixed> $values     the supplied parameters, by name
     * @param bool                 $withPeriod false for a report without a period (the admin summary): `from`/`to` are ignored and the default period stands
     */
    public function fromValues(array $values, ?Uuid $linkId, bool $withGranularity = false, bool $withLimit = false, bool $withPeriod = true): ReportRequest
    {
        try {
            return $this->parse($values, $linkId, $withGranularity, $withLimit, $withPeriod);
        } catch (InvalidReportParameter $e) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($e->getMessage(), null, [], null, $e->parameter, $values[$e->parameter] ?? null)]));
        }
    }

    /**
     * The same parsing with the refusal left as it is raised, so a caller that
     * is not an API operation can render it where it belongs — a form control
     * on a statistics page (design decision 2 of add-web-admin-and-stats).
     * `fromRequest()` is this method plus API Platform's 422.
     *
     * @param array<string, mixed> $values the supplied parameters, by name
     *
     * @throws InvalidReportParameter naming the parameter that was refused
     */
    public function parse(array $values, ?Uuid $linkId, bool $withGranularity = false, bool $withLimit = false, bool $withPeriod = true): ReportRequest
    {
        $period = $withPeriod
            ? Period::of(self::text($values, 'from'), self::text($values, 'to'), $this->clock)
            : Period::defaults($this->clock);
        $granularity = Granularity::Day;
        if ($withGranularity && null !== self::text($values, 'granularity')) {
            $granularity = Granularity::tryFrom((string) self::text($values, 'granularity')) ?? throw new InvalidReportParameter('granularity', 'granularity must be hour or day.');
        }
        $limit = ReportRequest::DEFAULT_LIMIT;
        if ($withLimit && null !== self::text($values, 'limit')) {
            $raw = (string) self::text($values, 'limit');
            if (!ctype_digit($raw)) {
                throw new InvalidReportParameter('limit', 'limit must be an integer.');
            }
            $limit = (int) $raw;
        }
        $includeBots = false;
        if (\array_key_exists('includeBots', $values) && null !== $values['includeBots']) {
            $includeBots = filter_var($values['includeBots'], \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? throw new InvalidReportParameter('includeBots', 'includeBots must be true or false.');
        }

        return new ReportRequest($linkId, $period, $granularity, $limit, $includeBots);
    }

    /**
     * One supplied value as the text the rules are written against, or null
     * when it was not supplied. A value of the wrong shape — an array where a
     * scalar belongs, which a query string can produce as easily as a GraphQL
     * argument — is refused by the rule that owns that parameter, not coerced
     * here.
     *
     * @param array<string, mixed> $values
     */
    private static function text(array $values, string $name): ?string
    {
        $value = $values[$name] ?? null;
        if (null === $value) {
            return null;
        }
        if (\is_array($value) || \is_object($value)) {
            throw new InvalidReportParameter($name, \sprintf('%s must be a single value.', $name));
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        throw new InvalidReportParameter($name, \sprintf('%s must be a single value.', $name));
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
