<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Auth\Entity\User;
use App\Link\LinkRepositoryInterface;
use App\Link\Rules\RulesDocumentParser;
use App\Link\UseCase\LinkChanges;
use App\Link\UseCase\UpdateLink;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * PATCH /api/v1/links/{id} as a merge patch: only the keys present in the
 * request body change (design decision 6 of add-links-and-redirect). This
 * class is the HTTP half — which keys the body named, the null contract
 * (`expiresAt`, `maxClicks`, `utm` and `rules` may be cleared with null;
 * `targetUrl` and `isActive` may not; the slug is immutable), and the
 * type-preserving decode of the rules document. The change itself, the flush,
 * the cache invalidation and the audit line are App\Link\UseCase\UpdateLink,
 * which the web UI calls with a LinkChanges built from a form instead
 * (add-web-ui, design decision 2).
 *
 * @implements ProcessorInterface<UpdateLinkInput, LinkResource>
 */
final readonly class UpdateLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private UpdateLink $updateLink,
        private Security $security,
        private PublicUrl $publicUrl,
        private RulesDocumentParser $rulesParser,
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

        $changes = new LinkChanges();
        if (isset($present['targetUrl']) && null !== $data->targetUrl) {
            $changes = $changes->withTarget($data->targetUrl);
        }
        if (isset($present['utm'])) {
            $changes = $changes->withUtm($data->utm);
        }
        if (isset($present['expiresAt'])) {
            $changes = $changes->withExpiry($data->expiresAt);
        }
        if (isset($present['maxClicks'])) {
            $changes = $changes->withClickLimit($data->maxClicks);
        }
        if (isset($present['rules'])) {
            $changes = $changes->withRules($this->canonicalRules($context));
        }
        if (isset($present['isActive']) && null !== $data->isActive) {
            $changes = $changes->withActive($data->isActive);
        }

        $actor = $this->security->getUser();
        ($this->updateLink)($link, $changes, $actor instanceof User ? $actor : null);

        return $this->publicUrl->toResource($link);
    }

    /**
     * The canonical form of the validated document from the raw body, or null
     * when the member is null (clear) — design decision 2.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function canonicalRules(array $context): ?array
    {
        $request = $context['request'] ?? null;
        $input = RulesInput::fromRequest($request instanceof Request ? $request : null);
        if (null === $input->node) {
            return null;
        }
        $document = $this->rulesParser->parse($input->node)->document;

        return $document?->toArray() ?? throw new \LogicException('The rules document was validated before processing.');
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
