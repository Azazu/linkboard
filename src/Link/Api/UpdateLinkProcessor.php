<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Auth\Entity\User;
use App\Link\LinkRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * PATCH /api/v1/links/{id} as a merge patch: only the keys present in the
 * request body change (design decision 6). Null contract: expiresAt,
 * maxClicks, utm may be cleared with null; targetUrl and isActive may not be
 * null; slug is immutable. Admin actions on another user's link are audited
 * after the flush.
 *
 * @implements ProcessorInterface<UpdateLinkInput, LinkResource>
 */
final readonly class UpdateLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private EntityManagerInterface $em,
        private Security $security,
        private LoggerInterface $auditLogger,
        private PublicUrl $publicUrl,
    ) {
    }

    /**
     * @param UpdateLinkInput      $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LinkResource
    {
        $present = self::presentKeys($context['request'] ?? null);
        $id = $uriVariables['id'] ?? null;
        $link = (\is_string($id) && Uuid::isValid($id)) ? $this->links->findById(Uuid::fromString($id)) : null;
        if (null === $link) {
            throw new NotFoundHttpException('No such link.');
        }

        $violations = new ConstraintViolationList();
        if (isset($present['slug']) && $data->slug !== $link->getSlug()) {
            $violations->add(new ConstraintViolation('The slug cannot be changed.', null, [], $data, 'slug', $data->slug));
        }
        if (isset($present['targetUrl']) && null === $data->targetUrl) {
            $violations->add(new ConstraintViolation('The target URL cannot be null.', null, [], $data, 'targetUrl', null));
        }
        if (isset($present['isActive']) && null === $data->isActive) {
            $violations->add(new ConstraintViolation('isActive must be true or false.', null, [], $data, 'isActive', null));
        }
        if (\count($violations) > 0) {
            throw new ValidationException($violations);
        }

        $now = new \DateTimeImmutable();
        $wasActive = $link->isActive();
        if (isset($present['targetUrl']) && null !== $data->targetUrl) {
            $link->changeTarget($data->targetUrl, $now);
        }
        if (isset($present['utm'])) {
            $link->replaceUtm($data->utm, $now);
        }
        if (isset($present['expiresAt'])) {
            $link->setExpiry($data->expiresAt, $now);
        }
        if (isset($present['maxClicks'])) {
            $link->setClickLimit($data->maxClicks, $now);
        }
        if (isset($present['isActive']) && null !== $data->isActive) {
            $data->isActive ? $link->activate($now) : $link->deactivate($now);
        }
        $this->em->flush();

        $actor = $this->security->getUser();
        if ($actor instanceof User && !$actor->getId()->equals($link->getOwner()->getId())) {
            $action = match (true) {
                $wasActive && !$link->isActive() => 'link.deactivate',
                !$wasActive && $link->isActive() => 'link.activate',
                default => 'link.update',
            };
            // after the flush: a failed write leaves no audit line
            $this->auditLogger->info($action, [
                'action' => $action,
                'actor_id' => (string) $actor->getId(),
                'target_id' => (string) $link->getId(),
                'owner_id' => (string) $link->getOwner()->getId(),
            ]);
        }

        return $this->publicUrl->toResource($link);
    }

    /**
     * @return array<string, true>
     */
    private static function presentKeys(?Request $request): array
    {
        if (null === $request) {
            return [];
        }
        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded) || array_is_list($decoded) && [] !== $decoded) {
            throw new BadRequestHttpException('The body must be a JSON object.');
        }

        return array_fill_keys(array_map(strval(...), array_keys($decoded)), true);
    }
}
