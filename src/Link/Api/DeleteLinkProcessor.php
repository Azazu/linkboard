<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Analytics\Cache\ReportCache;
use App\Auth\Entity\User;
use App\Click\Counter\ClickCounterInterface;
use App\Link\LinkRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/v1/links/{id} — hard delete (FR-LNK-10); dependents cascade
 * through the FK contract, the Redis click counter is removed best effort after
 * the flush (a counter failure is logged, never fails the deletion), the
 * link's cached reports and the global statistics are invalidated (best
 * effort, capability analytics), queued click messages are discarded by
 * their handler. Admin deletions of another user's link are audited after
 * the flush.
 *
 * @implements ProcessorInterface<LinkResource, null>
 */
final readonly class DeleteLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private EntityManagerInterface $em,
        private Security $security,
        private LoggerInterface $auditLogger,
        private ClickCounterInterface $counter,
        private LoggerInterface $logger,
        private ReportCache $reportCache,
    ) {
    }

    /**
     * @param LinkResource         $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $link = $this->links->findById(Uuid::fromString($data->id)) ?? throw new NotFoundHttpException('No such link.');
        $ownerId = (string) $link->getOwner()->getId();
        $linkId = (string) $link->getId();

        $this->links->remove($link);
        $this->em->flush();

        try {
            $this->counter->forget($link->getId());
        } catch (\Throwable $e) {
            $this->logger->warning('Click counter key not removed with the link', ['link_id' => $linkId, 'exception' => $e::class]);
        }
        $this->reportCache->forgetLink($link->getId());
        $this->reportCache->forgetGlobal($link->getId());

        $actor = $this->security->getUser();
        if ($actor instanceof User && (string) $actor->getId() !== $ownerId) {
            $this->auditLogger->info('link.delete', [
                'action' => 'link.delete',
                'actor_id' => (string) $actor->getId(),
                'target_id' => $linkId,
                'owner_id' => $ownerId,
            ]);
        }

        return null;
    }
}
