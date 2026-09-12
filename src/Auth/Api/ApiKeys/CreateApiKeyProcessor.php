<?php

declare(strict_types=1);

namespace App\Auth\Api\ApiKeys;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Auth\ApiKey\ApiKeyGenerator;
use App\Auth\ApiKeyRepositoryInterface;
use App\Auth\Entity\ApiKey;
use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * POST /api/v1/api-keys (design decision 5). One database transaction: lock
 * the owner's row, count the active keys, insert — so concurrent creations
 * for one owner serialise and the cap of ApiKey::MAX_ACTIVE_PER_USER holds;
 * the loser re-counts under a fresh snapshot, sees the cap and gets 409 with
 * nothing created. The plaintext exists only in the returned DTO.
 *
 * @implements ProcessorInterface<CreateApiKeyInput, CreatedApiKeyOutput>
 */
final readonly class CreateApiKeyProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiKeyRepositoryInterface $keys,
        private ApiKeyGenerator $generator,
        private EntityManagerInterface $em,
        private Security $security,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param CreateApiKeyInput    $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CreatedApiKeyOutput
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $this->create($user, $data);
    }

    /** Public so the concurrency fixture can drive exactly the production path with an explicit owner. */
    public function create(User $owner, CreateApiKeyInput $input): CreatedApiKeyOutput
    {
        $now = $this->clock->now();
        $generated = $this->generator->generate();

        // DBAL's transactional() rolls back without closing the EntityManager on
        // the conflict path (EntityManager::wrapInTransaction would close it).
        $key = $this->em->getConnection()->transactional(function () use ($owner, $input, $generated, $now): ApiKey {
            $this->keys->lockOwner($owner);
            if ($this->keys->countActiveByOwner($owner, $now) >= ApiKey::MAX_ACTIVE_PER_USER) {
                throw new ConflictHttpException(\sprintf('At most %d active API keys per user.', ApiKey::MAX_ACTIVE_PER_USER));
            }
            $key = new ApiKey($owner, $input->name, $generated->hash, $generated->prefix, $input->expiresAt, $now);
            $this->keys->add($key);
            $this->em->flush();

            return $key;
        });

        return CreatedApiKeyOutput::fromKey($key, $generated->plaintext);
    }
}
