<?php

declare(strict_types=1);

namespace App\Web\ApiKey;

use App\Auth\Api\ApiKeys\CreateApiKeyInput;
use App\Auth\Api\ApiKeys\CreateApiKeyProcessor;
use App\Auth\Api\ApiKeys\RevokeApiKeyProcessor;
use App\Auth\ApiKeyRepositoryInterface;
use App\Auth\Entity\ApiKey;
use App\Auth\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * `/api-keys` (FR-WEB-1, spec api-keys): the user's own keys, creating one —
 * whose plaintext the server sends in exactly one response and can never
 * produce again — and revoking one.
 *
 * The secret's handling is design decision 9a of add-web-ui: the value is
 * shown once, the page carrying it is kept out of the navigation layer's
 * cache and out of HTTP caches, and a document the browser restores by itself
 * is cleared by a small controller. What the server guarantees is that it
 * never sends the value twice; the rest is stated as mitigation, here and in
 * the spec.
 */
#[IsGranted(User::ROLE_USER)]
final class ApiKeyPageController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly ApiKeyRepositoryInterface $keys,
        private readonly CreateApiKeyProcessor $createKey,
        private readonly RevokeApiKeyProcessor $revokeKey,
    ) {
    }

    #[Route('/api-keys', name: 'app_api_keys', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $data = new ApiKeyFormData();
        $form = $this->createForm(ApiKeyType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $input = new CreateApiKeyInput();
            $input->name = $data->name;
            $input->expiresAt = $data->expiresAt?->format(CreateApiKeyInput::EXPIRES_AT_FORMAT);
            try {
                $key = $this->createKey->create($user, $input);
                // A 303 to this same page, not a rendered 200: Turbo replaces a
                // page only on a redirect or a 422, and a redirect also means a
                // reload cannot create a second key. The value rides one flash —
                // server-side, read once, gone — rather than living in a URL or
                // in a resubmittable POST body.
                $this->addFlash('api_key_created', ['key' => $key->key, 'prefix' => $key->prefix, 'name' => $key->name]);

                return $this->redirectToRoute('app_api_keys', [], Response::HTTP_SEE_OTHER);
            } catch (ConflictHttpException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        $session = $request->getSession();
        $flashed = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get('api_key_created') : [];
        $created = \is_array($flashed[0] ?? null) ? $flashed[0] : null;

        $response = $this->render('api_key/index.html.twig', [
            'form' => $form,
            'created' => $created,
            'keys' => $this->keys->listByOwner($user, 0, self::PER_PAGE),
            'total' => $this->keys->countByOwner($user),
            'max_active' => ApiKey::MAX_ACTIVE_PER_USER,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));

        if (null !== $created) {
            // the response that carries a secret is stored by nothing
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    #[Route('/api-keys/{id}/revoke', name: 'app_api_key_revoke', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function revoke(string $id, Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('No such API key.');
        }
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->revokeKey->revoke($user, Uuid::fromString($id));
        $this->addFlash('success', 'The key was revoked.');

        return $this->redirectToRoute('app_api_keys', [], Response::HTTP_SEE_OTHER);
    }
}
