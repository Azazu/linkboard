<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Auth\Entity\User;
use App\Link\Security\LinkVoter;
use App\Link\UseCase\UpdateLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `/links/{id}/edit` (FR-WEB-1): target, expiry, click limit, UTM members,
 * active state and the routing rules. A form carries every field, so an
 * emptied field means *clear it* — which is what LinkChanges expresses and
 * what the API's merge patch expresses with an explicit null.
 */
#[IsGranted(User::ROLE_USER)]
final class LinkEditController extends AbstractController
{
    public function __construct(
        private readonly LinkPages $pages,
        private readonly UpdateLink $updateLink,
    ) {
    }

    #[Route('/links/{id}/edit', name: 'app_link_edit', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        $link = $this->pages->findGranted($id, LinkVoter::EDIT);
        $data = LinkFormData::fromLink($link);
        $form = $this->createForm(LinkType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rules = $this->pages->rulesFrom($form, $data->rules);
            if (false !== $rules) {
                ($this->updateLink)($link, $this->pages->changesFrom($data, $rules), $this->pages->actor());
                $this->addFlash('success', 'The link was updated.');

                return $this->redirectToRoute('app_link_show', ['id' => $id], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('link/edit.html.twig', [
            'form' => $form,
            'link' => $link,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
