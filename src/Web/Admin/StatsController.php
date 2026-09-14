<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Analytics\Api\ReportRequestFactory;
use App\Analytics\Report\GlobalReports;
use App\Auth\Entity\User;
use App\Web\Stats\StatsChart;
use App\Web\Stats\StatsControls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;

/**
 * `/admin/stats` (FR-WEB-1, spec analytics "Global statistics for
 * administrators"): the service-wide totals, the clicks per bucket over every
 * link with its running total, and the links with the most clicks in the
 * period.
 *
 * The reports come from `GlobalReports`, the service the API's provider calls,
 * so the page and `GET /api/v1/admin/stats/...` share one cache entry (design
 * decision 1). The controls and their refusals behave exactly as on a link's
 * statistics page — the same `StatsControls`.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class StatsController extends AbstractController
{
    public function __construct(
        private readonly GlobalReports $reports,
        private readonly ReportRequestFactory $requests,
        private readonly ChartBuilderInterface $charts,
    ) {
    }

    #[Route('/admin/stats', name: 'app_admin_stats', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $controls = StatsControls::from($request, null, $this->requests);

        if ($controls->refused()) {
            return $this->render('admin/stats.html.twig', [
                'controls' => $controls,
                'reports' => null,
                'chart' => null,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $for = $controls->request;
        \assert(null !== $for);
        $timeseries = $this->reports->timeseries($for);

        return $this->render('admin/stats.html.twig', [
            'controls' => $controls,
            'reports' => [
                // the summary has no period of its own: it is all-time plus today
                'summary' => $this->reports->summary($for),
                'timeseries' => $timeseries,
                'topLinks' => $this->reports->topLinks($for),
            ],
            'chart' => StatsChart::ofBuckets($this->charts, $timeseries->buckets, $for->granularity),
        ]);
    }
}
