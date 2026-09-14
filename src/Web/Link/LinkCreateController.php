<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Auth\Entity\User;
use App\Link\UseCase\CreateLink;
use App\Link\UseCase\NewLink;
use App\Link\UseCase\SlugTaken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `/links/new` (FR-WEB-1). The creation itself is App\Link\UseCase\CreateLink,
 * the same one the API calls; this page only turns a form into its input and a
 * refused slug into a field error.
 */
#[IsGranted(User::ROLE_USER)]
final class LinkCreateController extends AbstractController
{
    public function __construct(
        private readonly CreateLink $createLink,
        private readonly LinkPages $pages,
    ) {
    }

    #[Route('/links/new', name: 'app_link_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $data = new LinkFormData();
        $form = $this->createForm(LinkType::class, $data, ['creating' => true]);
        $form->handleRequest($request);

        // a switch between the two views of the rules is a submission of its own:
        // it carries the document across and re-renders, it never saves. The form
        // is rebuilt from the carried-over data, because a form that has handled
        // a request renders what was submitted, not what the model holds now.
        $switch = $form->isSubmitted() ? $this->pages->switchView($form, $data->rules) : RulesViewSwitch::none();
        if ($switch->happened) {
            $form = $this->createForm(LinkType::class, $data, ['creating' => true]);
            if (null !== $switch->message) {
                $form->get('rules')->get('raw')->addError(new FormError($switch->message));
            }
        }

        if (!$switch->happened && $form->isSubmitted() && $form->isValid()) {
            $rules = $this->pages->rulesFrom($form, $data->rules);
            if (false !== $rules) {
                try {
                    $link = ($this->createLink)($user, new NewLink(
                        targetUrl: $data->targetUrl,
                        slug: null === $data->slug || '' === trim($data->slug) ? null : trim($data->slug),
                        utm: $data->utm(),
                        expiresAt: $data->expiresAt,
                        maxClicks: $data->maxClicks,
                        rules: $rules,
                    ));

                    $this->addFlash('success', 'The link was created.');

                    // 303 after a write: Turbo replaces the page and a reload cannot re-post
                    return $this->redirectToRoute('app_link_show', ['id' => (string) $link->getId()], Response::HTTP_SEE_OTHER);
                } catch (SlugTaken $e) {
                    $form->get('slug')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('link/new.html.twig', ['form' => $form], new Response(status: $switch->happened || $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
