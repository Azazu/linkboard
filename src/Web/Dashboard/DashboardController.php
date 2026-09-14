<?php

declare(strict_types=1);

namespace App\Web\Dashboard;

use App\Analytics\Query\OwnerDashboardQuery;
use App\Analytics\Report\Period;
use App\Auth\Entity\User;
use App\Link\LinkListQuery;
use App\Link\LinkRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * `/dashboard` (FR-WEB-1): the signed-in user's own totals, a clicks-per-day
 * chart over their links and their ten most recent links. Every figure comes
 * from a query scoped by owner in SQL (design decision 3 of add-web-ui), so a
 * row that is not theirs cannot be rendered even by mistake.
 */
#[IsGranted(User::ROLE_USER)]
final class DashboardController extends AbstractController
{
    private const int RECENT_LINKS = 10;

    public function __construct(
        private readonly OwnerDashboardQuery $dashboard,
        private readonly LinkRepositoryInterface $links,
        private readonly ClockInterface $clock,
        private readonly ChartBuilderInterface $charts,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $ownerId = $user->getId();

        $period = Period::defaults($this->clock);
        $days = $this->dashboard->daily($ownerId, $period);

        $chart = $this->charts->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => array_map(static fn ($bucket): string => $bucket->bucket->format('Y-m-d'), $days),
            'datasets' => [[
                'label' => 'Clicks',
                'data' => array_map(static fn ($bucket): int => $bucket->clicks, $days),
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

        return $this->render('dashboard/index.html.twig', [
            'totals' => $this->dashboard->totals($ownerId, $this->startOfToday()),
            'days' => $days,
            'chart' => $chart,
            'period' => $period,
            'recent' => $this->links->findPageForOwner($ownerId, new LinkListQuery(), 0, self::RECENT_LINKS),
        ]);
    }

    private function startOfToday(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
    }
}
