<?php

declare(strict_types=1);

namespace App\Link\UseCase;

use App\Auth\Entity\User;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Link\SlugGeneratorInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creating a link, for both callers (design decision 2 of add-web-ui): the API
 * processor and the web controller differ only in where the input came from
 * and how a refused slug is rendered.
 *
 * Slug collision recovery (links capability, design decision 3 of
 * add-links-and-redirect): a failed Doctrine commit closes the EntityManager,
 * so after a unique violation on a *generated* slug the manager is reset, the
 * Link is rebuilt with the owner re-attached through getReference, and the next
 * candidate is tried — up to MAX_ATTEMPTS. A slug the caller chose is never
 * retried: it raises SlugTaken.
 */
final readonly class CreateLink
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private LinkRepositoryInterface $links,
        private ManagerRegistry $doctrine,
        private SlugGeneratorInterface $slugs,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws SlugTaken when the caller supplied a slug another link already uses
     */
    public function __invoke(User $owner, NewLink $data): Link
    {
        $ownerId = $owner->getId();

        if (null !== $data->slug) {
            return $this->persist($ownerId, $data->slug, $data, clientSupplied: true);
        }

        $collisions = 0;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $candidate = $this->slugs->generate();
            if ($this->links->slugExists($candidate)) {
                ++$collisions;
                continue; // cheap pre-check; the unique index below is the guarantee
            }
            try {
                return $this->persist($ownerId, $candidate, $data, clientSupplied: false);
            } catch (UniqueConstraintViolationException) {
                ++$collisions;
                // the failed commit closed the manager: replace it and try the next candidate
                $this->doctrine->resetManager();
            }
        }

        $this->logger->error('Could not find a free generated slug.', ['attempts' => self::MAX_ATTEMPTS, 'collisions' => $collisions]);
        throw new \RuntimeException(\sprintf('No free slug after %d attempts.', self::MAX_ATTEMPTS));
    }

    private function persist(Uuid $ownerId, string $slug, NewLink $data, bool $clientSupplied): Link
    {
        $em = $this->doctrine->getManager();
        \assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        // a reference on the *current* manager: the previous one may have been closed and replaced
        $owner = $em->getReference(User::class, $ownerId);
        \assert($owner instanceof User);
        $now = $this->clock->now();
        $link = new Link($owner, $slug, $data->targetUrl, $now);
        if (null !== $data->utm) {
            $link->replaceUtm($data->utm, $now);
        }
        $link->setExpiry($data->expiresAt, $now);
        $link->setClickLimit($data->maxClicks, $now);
        if (null !== $data->rules) {
            $link->replaceRules($data->rules, $now);
        }
        $em->persist($link);

        try {
            $em->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!$clientSupplied) {
                throw $e;
            }

            throw new SlugTaken($slug, $e);
        }

        return $link;
    }
}
