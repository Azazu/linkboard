<?php

declare(strict_types=1);

namespace App\Web\Stats;

use App\Analytics\Api\ReportRequestFactory;
use App\Analytics\Report\LinkReports;
use App\Auth\Entity\User;
use App\Link\Security\LinkVoter;
use App\Web\Link\LinkPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;

/**
 * `/links/{id}/stats` (FR-WEB-1): every report the `analytics` capability
 * defines for one link.
 *
 * Two things are deliberate. The permission is the voter's own LINK_VIEW on
 * the converted resource — the very attribute the API's report provider asks —
 * answered with 404 as every other link page answers a denial (design decision
 * 4). And the reports come from `LinkReports`, the same service the API calls,
 * so this page and `GET /api/v1/links/{id}/stats/...` share one cache entry and
 * cannot disagree about a number (design decision 1).
 */
#[IsGranted(User::ROLE_USER)]
final class LinkStatsController extends AbstractController
{
    public function __construct(
        private readonly LinkPages $pages,
        private readonly LinkReports $reports,
        private readonly ReportRequestFactory $requests,
        private readonly ChartBuilderInterface $charts,
    ) {
    }

    #[Route('/links/{id}/stats', name: 'app_link_stats', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function __invoke(string $id, Request $request): Response
    {
        $link = $this->pages->findGranted($id, LinkVoter::VIEW);
        $controls = StatsControls::from($request, $link->getId(), $this->requests);

        if ($controls->refused()) {
            // the parameters were refused, not the page: it renders, with the
            // message on the control that carried the value and no figures
            return $this->render('stats/link.html.twig', [
                'link' => $this->pages->resource($link),
                'controls' => $controls,
                'reports' => null,
                'chart' => null,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $for = $controls->request;
        \assert(null !== $for);
        $timeseries = $this->reports->timeseries($for);

        return $this->render('stats/link.html.twig', [
            'link' => $this->pages->resource($link),
            'controls' => $controls,
            'reports' => [
                'summary' => $this->reports->summary($for),
                'timeseries' => $timeseries,
                'countries' => $this->reports->countries($for),
                'devices' => $this->reports->devices($for),
                'referrers' => $this->reports->referrers($for),
                'variants' => $this->reports->variants($for),
            ],
            'chart' => StatsChart::ofBuckets($this->charts, $timeseries->buckets, $for->granularity),
        ]);
    }
}
