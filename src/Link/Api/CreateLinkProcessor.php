<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Auth\Entity\User;
use App\Link\Rules\RulesDocumentParser;
use App\Link\UseCase\CreateLink;
use App\Link\UseCase\NewLink;
use App\Link\UseCase\SlugTaken;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * POST /api/v1/links. The HTTP half of creating a link: the owner comes from
 * the token, the rules document from the raw body (design decision 2 of
 * add-links-and-redirect — the type-preserving decode a DTO cannot do), and a
 * slug the client chose and lost the race for becomes the 422 the validator
 * would have given. The creation itself — the slug loop, the collision
 * recovery, the flush — is App\Link\UseCase\CreateLink, which the web UI
 * calls too (add-web-ui, design decision 2).
 *
 * @implements ProcessorInterface<CreateLinkInput, LinkResource>
 */
final readonly class CreateLinkProcessor implements ProcessorInterface
{
    public function __construct(
        private CreateLink $createLink,
        private Security $security,
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

        $new = new NewLink(
            targetUrl: $data->targetUrl,
            slug: $data->slug,
            utm: $data->utm,
            expiresAt: $data->expiresAt,
            maxClicks: $data->maxClicks,
            rules: $this->canonicalRules($context),
        );

        try {
            $link = ($this->createLink)($actor, $new);
        } catch (SlugTaken $e) {
            // lost the race for a custom slug: the same 422 the validator gives
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($e->getMessage(), null, [], $data, 'slug', $e->slug)]));
        }

        return $this->publicUrl->toResource($link);
    }

    /**
     * The canonical form of the validated document, from the raw body.
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
}
