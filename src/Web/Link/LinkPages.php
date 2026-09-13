<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Auth\Entity\User;
use App\Link\Api\PublicUrl;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Link\Rules\RulesDocumentParser;
use App\Link\Security\LinkVoter;
use App\Link\UseCase\LinkChanges;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * What every link page does the same way (design decision 3 of add-web-ui):
 * find the link, ask the voter on the subject the voter actually votes on —
 * the LinkResource, not the entity — and answer 404 for a link the signed-in
 * user may not see, so that an existing link owned by somebody else is
 * indistinguishable from an identifier no link has. The API keeps its own
 * answer for the same decision (403, spec links); this is the pages' rendering.
 */
final readonly class LinkPages
{
    public function __construct(
        private LinkRepositoryInterface $links,
        private PublicUrl $publicUrl,
        private Security $security,
        private RulesDocumentParser $rulesParser,
    ) {
    }

    /**
     * @param string $attribute LinkVoter::VIEW|EDIT|DELETE
     */
    public function findGranted(?string $id, string $attribute): Link
    {
        $link = (\is_string($id) && Uuid::isValid($id)) ? $this->links->findById(Uuid::fromString($id)) : null;
        if (null === $link || !$this->security->isGranted($attribute, $this->publicUrl->toResource($link))) {
            throw new NotFoundHttpException('No such link.');
        }

        return $link;
    }

    public function resource(Link $link): \App\Link\Api\LinkResource
    {
        return $this->publicUrl->toResource($link);
    }

    public function actor(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * The rules document a submission means, with every violation put where it
     * belongs on the form. Returns the canonical document, or null to clear the
     * rules; false means the form has errors and nothing should be written.
     *
     * @param FormInterface<mixed> $form
     *
     * @return array<string, mixed>|false|null
     */
    public function rulesFrom(FormInterface $form, RulesFormData $data): array|false|null
    {
        try {
            $node = RulesDocumentMapper::toNode($data);
        } catch (\JsonException $e) {
            $form->get('rules')->get('raw')->addError(new FormError('The document is not valid JSON: '.$e->getMessage()));

            return false;
        }
        if (null === $node) {
            return null;
        }

        $result = $this->rulesParser->parse($node);
        if ($result->isValid()) {
            return $result->document?->toArray();
        }

        $rules = $form->get('rules');
        foreach ($result->violations as $violation) {
            [$row, $field] = RuleViolationMapper::locate($violation->path);
            $located = !$data->usesRawDocument() && null !== $row && null !== $field && $rules->get('rows')->has((string) $row);
            $target = $located
                ? $rules->get('rows')->get((string) $row)->get((string) $field)
                : ($data->usesRawDocument() ? $rules->get('raw') : $rules);
            // a violation the rows cannot hold keeps its document path in the message
            $target->addError(new FormError(RuleViolationMapper::message($violation, $located)));
        }

        return false;
    }

    /**
     * The changes an edit form means: every field the form carries is named, so
     * an emptied field clears.
     *
     * @param array<string, mixed>|null $rules
     */
    public function changesFrom(LinkFormData $data, ?array $rules): LinkChanges
    {
        return (new LinkChanges())
            ->withTarget($data->targetUrl)
            ->withUtm($data->utm())
            ->withExpiry($data->expiresAt)
            ->withClickLimit($data->maxClicks)
            ->withRules($rules)
            ->withActive($data->isActive);
    }
}
