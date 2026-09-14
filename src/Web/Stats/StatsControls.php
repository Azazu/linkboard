<?php

declare(strict_types=1);

namespace App\Web\Stats;

use App\Analytics\Api\ReportRequestFactory;
use App\Analytics\Report\InvalidReportParameter;
use App\Analytics\Report\ReportRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * The period, granularity and bots controls of a statistics page, and what the
 * `analytics` capability made of them (design decision 2 of
 * add-web-admin-and-stats).
 *
 * The rules themselves are not restated here: the report layer parses, and a
 * refusal arrives naming the parameter that carried it, which is exactly what
 * a control needs to show the message in the right place. `values` is what the
 * reader typed, so the page comes back with their own input rather than with a
 * silently corrected one.
 */
final readonly class StatsControls
{
    /** What a `datetime-local` control sends and shows: minute precision, no zone. */
    public const string MOMENT = 'Y-m-d\TH:i';

    /**
     * @param ?ReportRequest        $request null when a parameter was refused — there is nothing to report on
     * @param array<string, string> $values  what the controls should show back
     */
    private function __construct(
        public ?ReportRequest $request,
        public ?string $parameter,
        public ?string $message,
        public array $values,
    ) {
    }

    public static function from(Request $http, ?Uuid $linkId, ReportRequestFactory $factory): self
    {
        $values = [
            'from' => trim((string) $http->query->get('from', '')),
            'to' => trim((string) $http->query->get('to', '')),
            'granularity' => (string) $http->query->get('granularity', 'day'),
            'limit' => trim((string) $http->query->get('limit', '')),
            'includeBots' => $http->query->has('includeBots') ? '1' : '',
        ];

        $query = $http->query->all();
        foreach (['from', 'to'] as $bound) {
            if ('' === $values[$bound]) {
                unset($query[$bound]);
                continue;
            }
            $query[$bound] = self::asMoment($values[$bound]);
        }

        try {
            $request = $factory->parse(
                new Request($query),
                $linkId,
                withGranularity: true,
                withLimit: true,
            );
        } catch (InvalidReportParameter $e) {
            return new self(null, $e->parameter, $e->getMessage(), $values);
        }

        // the effective parameters, so the controls show what is actually
        // reported — and keep the precision the request was made with, rather
        // than truncating it to a day and reporting something else next time
        $values['from'] = $request->period->from->format(self::MOMENT);
        $values['to'] = $request->period->to->format(self::MOMENT);
        $values['granularity'] = $request->granularity->value;
        $values['limit'] = (string) $request->limit;
        $values['includeBots'] = $request->includeBots ? '1' : '';

        return new self($request, null, null, $values);
    }

    /**
     * The page's own shorthands widened to the RFC 3339 the `analytics`
     * capability speaks: a day from a date control, a minute from a
     * `datetime-local` one, both read as UTC because that is what the page
     * says they are. Anything else passes through for the report layer to
     * refuse in its own words.
     */
    private static function asMoment(string $value): string
    {
        return match (true) {
            1 === preg_match('/^\d{4}-\d\d-\d\d$/', $value) => $value.'T00:00:00Z',
            1 === preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/', $value) => $value.':00Z',
            1 === preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', $value) => $value.'Z',
            default => $value,
        };
    }

    public function refused(): bool
    {
        return null === $this->request;
    }

    /** The message belonging to this control, if it is the one that was refused. */
    public function messageFor(string $parameter): ?string
    {
        return $this->parameter === $parameter ? $this->message : null;
    }
}
