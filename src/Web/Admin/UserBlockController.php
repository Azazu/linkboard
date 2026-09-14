<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Entity\User;
use App\Auth\UseCase\BlockUser;
use App\Auth\UseCase\CannotBlockOwnAccount;
use App\Auth\UseCase\UnblockUser;
use App\Auth\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Blocking and unblocking an account from a page (spec web-ui). Both ask
 * first: the list links here, this page states the consequence and offers a
 * way back, and only its own form — a POST with a valid token — acts. Neither
 * needs JavaScript.
 *
 * The action itself is `BlockUser`/`UnblockUser`, which the API's processors
 * call too (design decision 5), so the self-block guard and the audit line
 * cannot differ between the two entry points. The role is checked here as well
 * as by `access_control`: a valid token proves the request was not forged, it
 * says nothing about who may make it.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class UserBlockController extends AbstractController
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly BlockUser $block,
        private readonly UnblockUser $unblock,
    ) {
    }

    #[Route('/admin/users/{id}/block', name: 'app_admin_user_block', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    public function block(string $id, Request $request): Response
    {
        return $this->act($id, $request, blocking: true);
    }

    #[Route('/admin/users/{id}/unblock', name: 'app_admin_user_unblock', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET', 'POST'])]
    public function unblock(string $id, Request $request): Response
    {
        return $this->act($id, $request, blocking: false);
    }

    private function act(string $id, Request $request, bool $blocking): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('No such account.');
        }

        if ($request->isMethod('GET')) {
            return $this->render('admin/user_action.html.twig', [
                'account' => $this->account($id),
                'blocking' => $blocking,
            ]);
        }

        // the token first on the write, as on every state-changing page here
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $account = $this->account($id);
        try {
            $blocking ? ($this->block)($account) : ($this->unblock)($account);
        } catch (CannotBlockOwnAccount $e) {
            // the rule lives with the action; the page only renders it
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_users', [], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', \sprintf('%s was %s.', $account->getEmail(), $blocking ? 'blocked' : 'unblocked'));

        return $this->redirectToRoute('app_admin_users', [], Response::HTTP_SEE_OTHER);
    }

    private function account(string $id): User
    {
        return $this->users->findById(Uuid::fromString($id)) ?? throw new NotFoundHttpException('No such account.');
    }
}
