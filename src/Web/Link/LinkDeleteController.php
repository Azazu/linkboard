<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Auth\Entity\User;
use App\Link\Security\LinkVoter;
use App\Link\UseCase\DeleteLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Deleting a link asks first, like revoking a key (spec web-ui): this page
 * says what deletion does, and only its own form — a POST with a valid token —
 * deletes. A link in an e-mail cannot delete anything, and neither can a first
 * click. It needs no JavaScript.
 */
#[IsGranted(User::ROLE_USER)]
final class LinkDeleteController extends AbstractController
{
    public function __construct(
        private readonly LinkPages $pages,
        private readonly DeleteLink $deleteLink,
    ) {
    }

    #[Route('/links/{id}/delete', name: 'app_link_delete', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('link/delete.html.twig', ['link' => $this->pages->findGranted($id, LinkVoter::DELETE)]);
        }

        // the token first, as on every state-changing page of this UI: a forged
        // request is refused before anything is looked up, and a well-formed one
        // by a stranger then gets the same 404 as an identifier no link has
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $link = $this->pages->findGranted($id, LinkVoter::DELETE);

        ($this->deleteLink)($link, $this->pages->actor());
        $this->addFlash('success', 'The link was deleted.');

        return $this->redirectToRoute('app_links', [], Response::HTTP_SEE_OTHER);
    }
}
