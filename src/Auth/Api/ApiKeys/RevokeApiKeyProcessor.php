<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Auth\ApiKeyRepositoryInterface;
use App\Auth\Entity\User;
use App\Auth\Security\ApiKeyVoter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/v1/api-keys/{id} — revocation (spec api-keys): revoked_at is
 * set once by a conditional UPDATE (… WHERE revoked_at IS NULL), so repeated
 * or concurrent revocations keep the first timestamp; the row stays, the key
 * stops authenticating on the next request. The provider already scoped the
 * key to the caller; the voter is asked as well (defence in depth, the same
 * rule the web UI will call).
 *
 * @implements ProcessorInterface<ApiKeyOutput, null>
 */
final readonly class RevokeApiKeyProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiKeyRepositoryInterface $keys,
        private Security $security,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param ApiKeyOutput         $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }
        $key = $this->keys->findByIdAndOwner(Uuid::fromString($data->id), $user) ?? throw new NotFoundHttpException('No such API key.');
        if (!$this->security->isGranted(ApiKeyVoter::REVOKE, $key)) {
            throw new AccessDeniedException();
        }
        $this->keys->revoke($key->getId(), $this->clock->now());

        return null;
    }
}
