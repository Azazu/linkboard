<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Analytics\Report\LinkReports;
use App\Analytics\Report\ReportRequest;
use App\Link\Api\PublicUrl;
use App\Link\LinkRepositoryInterface;
use App\Link\Security\LinkVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * The six per-link reports (design decisions 5–7 of add-analytics-read-model):
 * 404 for a malformed or unknown id before any authorization check, the
 * existing LinkVoter's LINK_VIEW on the link — the permission matrix's "view a
 * link's analytics" — then the report.
 *
 * Computing it is not this class's job: `LinkReports` owns the cache key, the
 * tag and the computation, and the statistics page calls the same service
 * (design decision 1 of add-web-admin-and-stats). What is left here is what
 * belongs to API Platform: an operation and a query string in, a report out.
 *
 * @implements ProviderInterface<object>
 */
final readonly class LinkReportProvider implements ProviderInterface
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private PublicUrl $publicUrl,
        private Security $security,
        private ReportRequestFactory $requests,
        private LinkReports $reports,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('No such link.');
        }
        $link = $this->links->findById(Uuid::fromString($id)) ?? throw new NotFoundHttpException('No such link.');
        if (!$this->security->isGranted(LinkVoter::VIEW, $this->publicUrl->toResource($link))) {
            throw new AccessDeniedException('Only the owner or an admin may view a link\'s reports.');
        }

        $class = $operation->getClass() ?? throw new \LogicException('Report operations declare their class.');
        $report = $this->requests->fromValues(
            ReportParameters::fromContext($context),
            $link->getId(),
            withGranularity: LinkTimeseriesReport::class === $class,
            withLimit: \in_array($class, [LinkCountriesReport::class, LinkReferrersReport::class], true),
        );

        return $this->report($class, $report);
    }

    /**
     * @param class-string $class
     */
    private function report(string $class, ReportRequest $request): object
    {
        return match ($class) {
            LinkSummaryReport::class => $this->reports->summary($request),
            LinkTimeseriesReport::class => $this->reports->timeseries($request),
            LinkCountriesReport::class => $this->reports->countries($request),
            LinkDevicesReport::class => $this->reports->devices($request),
            LinkReferrersReport::class => $this->reports->referrers($request),
            LinkVariantsReport::class => $this->reports->variants($request),
            default => throw new \LogicException(\sprintf('No report for %s.', $class)),
        };
    }
}
