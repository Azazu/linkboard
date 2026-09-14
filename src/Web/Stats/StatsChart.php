<?php

declare(strict_types=1);

namespace App\Web\Stats;

use App\Analytics\Dto\ClickBucket;
use App\Analytics\Dto\TimeBucket;
use App\Analytics\Report\Granularity;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * The clicks-per-bucket line of a statistics page. The chart is an
 * enhancement: every bucket it draws is also a row in the table beside it
 * (design decision 7 of add-web-admin-and-stats), so a reader without
 * JavaScript loses the picture and not the figures.
 */
final readonly class StatsChart
{
    /**
     * @param list<ClickBucket|TimeBucket> $buckets
     */
    public static function ofBuckets(ChartBuilderInterface $charts, array $buckets, Granularity $granularity): Chart
    {
        $format = Granularity::Hour === $granularity ? 'Y-m-d H:i' : 'Y-m-d';
        $chart = $charts->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => array_map(static fn (ClickBucket|TimeBucket $b): string => $b->bucket->format($format), $buckets),
            'datasets' => [[
                'label' => 'Clicks',
                'data' => array_map(static fn (ClickBucket|TimeBucket $b): int => $b->clicks, $buckets),
                'borderColor' => '#0172ad',
                'fill' => false,
                'tension' => 0.2,
            ]],
        ]);
        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'plugins' => ['legend' => ['display' => false]],
        ]);

        return $chart;
    }
}
