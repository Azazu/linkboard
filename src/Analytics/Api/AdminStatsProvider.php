<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Analytics\Cache\ReportCache;
use App\Analytics\Query\BreakdownQuery;
use App\Analytics\Query\GlobalStatsQuery;
use App\Analytics\Query\TimeseriesQuery;
use App\Analytics\Report\ReportRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * The three global reports (spec analytics "Global statistics for
 * administrators"): ROLE_ADMIN is enforced by the operations and by
 * access_control on the prefix; the reports go through the cache under the
 * `global` tag.
 *
 * @implements ProviderInterface<object>
 */
final readonly class AdminStatsProvider implements ProviderInterface
{
    public function __construct(
        private ReportRequestFactory $requests,
        private ReportCache $cache,
        private GlobalStatsQuery $totals,
        private TimeseriesQuery $timeseries,
        private BreakdownQuery $breakdown,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $request = $context['request'] ?? null;
        $class = $operation->getClass() ?? throw new \LogicException('Report operations declare their class.');
        $report = $this->requests->fromRequest(
            $request instanceof Request ? $request : null,
            null,
            withGranularity: AdminTimeseriesReport::class === $class,
            withLimit: AdminTopLinksReport::class === $class,
            withPeriod: AdminSummaryReport::class !== $class, // the summary is all-time + today: no period, `from`/`to` ignored
        );
        $name = 'admin-'.strtolower((string) preg_replace('/^Admin(\w+)Report$/', '$1', substr($class, strrpos($class, '\\') + 1)));

        return $this->cache->remember($report->cacheKey($name), [$report->cacheTag()], fn (): object => $this->compute($class, $report));
    }

    /**
     * @param class-string $class
     */
    private function compute(string $class, ReportRequest $report): object
    {
        $now = $this->requests->now();

        return match ($class) {
            AdminSummaryReport::class => AdminSummaryReport::of($report, $this->totals->totals($report, $this->requests->startOfToday()), $now),
            AdminTimeseriesReport::class => AdminTimeseriesReport::of($report, $this->timeseries->clickBuckets($report), $now),
            AdminTopLinksReport::class => AdminTopLinksReport::of($report, $this->breakdown->topLinks($report), $now),
            default => throw new \LogicException(\sprintf('No report for %s.', $class)),
        };
    }
}
