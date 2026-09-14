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
            'includeBots' => $http->query->has('includeBots') ? '1' : '',
        ];

        // a date control sends a day, the capability speaks RFC 3339: widen the
        // page's own shorthand, and leave anything else for the report layer to
        // refuse in its own words
        $query = $http->query->all();
        foreach (['from', 'to'] as $bound) {
            if (1 === preg_match('/^\d{4}-\d\d-\d\d$/', $values[$bound])) {
                $query[$bound] = $values[$bound].'T00:00:00Z';
            }
            if ('' === $values[$bound]) {
                unset($query[$bound]);
            }
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

        // the effective period, so the controls show what is actually reported
        $values['from'] = $request->period->from->format('Y-m-d');
        $values['to'] = $request->period->to->format('Y-m-d');
        $values['granularity'] = $request->granularity->value;
        $values['includeBots'] = $request->includeBots ? '1' : '';

        return new self($request, null, null, $values);
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
