<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Analytics\Cache\ReportCache;
use App\Analytics\Query\BreakdownQuery;
use App\Analytics\Query\LinkSummaryQuery;
use App\Analytics\Query\TimeseriesQuery;
use App\Analytics\Report\ReportRequest;
use App\Link\Api\PublicUrl;
use App\Link\LinkRepositoryInterface;
use App\Link\Security\LinkVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * The six per-link reports (design decisions 5–7): 404 for a malformed or
 * unknown id before any authorization check, the existing LinkVoter's
 * LINK_VIEW on the link — the permission matrix's "view a link's analytics" —
 * then the report through the cache, tagged with the link.
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
        private ReportCache $cache,
        private LinkSummaryQuery $summary,
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
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('No such link.');
        }
        $link = $this->links->findById(Uuid::fromString($id)) ?? throw new NotFoundHttpException('No such link.');
        if (!$this->security->isGranted(LinkVoter::VIEW, $this->publicUrl->toResource($link))) {
            throw new AccessDeniedException('Only the owner or an admin may view a link\'s reports.');
        }

        $request = $context['request'] ?? null;
        $class = $operation->getClass() ?? throw new \LogicException('Report operations declare their class.');
        $report = $this->requests->fromRequest(
            $request instanceof Request ? $request : null,
            $link->getId(),
            withGranularity: LinkTimeseriesReport::class === $class,
            withLimit: \in_array($class, [LinkCountriesReport::class, LinkReferrersReport::class], true),
        );
        $name = strtolower((string) preg_replace('/^Link(\w+)Report$/', '$1', substr($class, strrpos($class, '\\') + 1)));

        return $this->cache->remember($report->cacheKey($name), [$report->cacheTag()], fn (): object => $this->compute($class, $report));
    }

    /**
     * @param class-string $class
     */
    private function compute(string $class, ReportRequest $report): object
    {
        $now = $this->requests->now();

        return match ($class) {
            LinkSummaryReport::class => LinkSummaryReport::of($report, $this->summary->figures($report, $this->requests->startOfToday()), $now),
            LinkTimeseriesReport::class => LinkTimeseriesReport::of($report, $this->timeseries->buckets($report), $now),
            LinkCountriesReport::class => LinkCountriesReport::of($report, $this->breakdown->countries($report), $now),
            LinkDevicesReport::class => LinkDevicesReport::of($report, $this->breakdown->devices($report), $now),
            LinkReferrersReport::class => LinkReferrersReport::of($report, $this->breakdown->referrers($report), $now),
            LinkVariantsReport::class => LinkVariantsReport::of($report, $this->breakdown->variants($report), $now),
            default => throw new \LogicException(\sprintf('No report for %s.', $class)),
        };
    }
}
