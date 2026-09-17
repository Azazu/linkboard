<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Analytics\Report\GlobalReports;
use App\Analytics\Report\ReportRequest;

/**
 * The three global reports (spec analytics "Global statistics for
 * administrators"): ROLE_ADMIN is enforced by the operations and by
 * access_control on the prefix.
 *
 * `GlobalReports` owns the cache key, the `global` tag and the computation,
 * and the administrative statistics page calls the same service (design
 * decision 1 of add-web-admin-and-stats); this class only turns an operation
 * and a query string into a report request.
 *
 * @implements ProviderInterface<object>
 */
final readonly class AdminStatsProvider implements ProviderInterface
{
    public function __construct(
        private ReportRequestFactory $requests,
        private GlobalReports $reports,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $class = $operation->getClass() ?? throw new \LogicException('Report operations declare their class.');
        $report = $this->requests->fromValues(
            ReportParameters::fromContext($context),
            null,
            withGranularity: AdminTimeseriesReport::class === $class,
            withLimit: AdminTopLinksReport::class === $class,
            withPeriod: AdminSummaryReport::class !== $class, // the summary is all-time + today: no period, `from`/`to` ignored
        );

        return $this->report($class, $report);
    }

    /**
     * @param class-string $class
     */
    private function report(string $class, ReportRequest $request): object
    {
        return match ($class) {
            AdminSummaryReport::class => $this->reports->summary($request),
            AdminTimeseriesReport::class => $this->reports->timeseries($request),
            AdminTopLinksReport::class => $this->reports->topLinks($request),
            default => throw new \LogicException(\sprintf('No report for %s.', $class)),
        };
    }
}
