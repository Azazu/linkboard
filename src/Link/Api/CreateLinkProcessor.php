<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Auth\Entity\User;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Link\Rules\RulesDocumentParser;
use App\Link\SlugGeneratorInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * POST /api/v1/links. Slug collision recovery per design decision 3: a
 * failed Doctrine commit closes the EntityManager, so after a unique
 * violation on a generated slug the manager is reset, a new Link is built
 * with the owner re-attached through getReference, and the next candidate is
 * tried — up to MAX_ATTEMPTS. A client-supplied slug is never retried.
 *
 * @implements ProcessorInterface<CreateLinkInput, LinkResource>
 */
final readonly class CreateLinkProcessor implements ProcessorInterface
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private LinkRepositoryInterface $links,
        private ManagerRegistry $doctrine,
        private SlugGeneratorInterface $slugs,
        private Security $security,
        private LoggerInterface $logger,
        private PublicUrl $publicUrl,
        private RulesDocumentParser $rulesParser,
    ) {
    }

    /**
     * @param CreateLinkInput      $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LinkResource
    {
        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            throw new AccessDeniedException();
        }
        $ownerId = $actor->getId();
        $rules = $this->canonicalRules($context);

        if (null !== $data->slug) {
            return $this->publicUrl->toResource($this->persist($ownerId, $data->slug, $data, $rules, clientSupplied: true));
        }

        $collisions = 0;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $candidate = $this->slugs->generate();
            if ($this->links->slugExists($candidate)) {
                ++$collisions;
                continue; // cheap pre-check; the unique index below is the guarantee
            }
            try {
                return $this->publicUrl->toResource($this->persist($ownerId, $candidate, $data, $rules, clientSupplied: false));
            } catch (UniqueConstraintViolationException) {
                ++$collisions;
                // the failed commit closed the manager: replace it and try the next candidate
                $this->doctrine->resetManager();
            }
        }

        $this->logger->error('Could not find a free generated slug.', ['attempts' => self::MAX_ATTEMPTS, 'collisions' => $collisions]);
        throw new \RuntimeException(\sprintf('No free slug after %d attempts.', self::MAX_ATTEMPTS));
    }

    /**
     * The canonical form of the validated document, from the raw body (design decision 2).
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function canonicalRules(array $context): ?array
    {
        $request = $context['request'] ?? null;
        $input = RulesInput::fromRequest($request instanceof Request ? $request : null);
        if (!$input->present || null === $input->node) {
            return null;
        }
        $document = $this->rulesParser->parse($input->node)->document;

        return $document?->toArray() ?? throw new \LogicException('The rules document was validated before processing.');
    }

    /**
     * @param array<string, mixed>|null $rules
     */
    private function persist(Uuid $ownerId, string $slug, CreateLinkInput $data, ?array $rules, bool $clientSupplied): Link
    {
        $em = $this->doctrine->getManager();
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        // a reference on the *current* manager: the previous one may have been closed and replaced
        $owner = $em->getReference(User::class, $ownerId);
        \assert($owner instanceof User);
        $now = new \DateTimeImmutable();
        $link = new Link($owner, $slug, $data->targetUrl, $now);
        if (null !== $data->utm) {
            $link->replaceUtm($data->utm, $now);
        }
        $link->setExpiry($data->expiresAt, $now);
        $link->setClickLimit($data->maxClicks, $now);
        if (null !== $rules) {
            $link->replaceRules($rules, $now);
        }
        $em->persist($link);

        try {
            $em->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!$clientSupplied) {
                throw $e;
            }
            // lost the race for a custom slug: the same 422 the validator gives
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('This slug is already taken.', null, [], $data, 'slug', $slug)]));
        }

        return $link;
    }
}
