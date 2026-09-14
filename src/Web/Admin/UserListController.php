<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `/admin/users` (FR-WEB-1, spec user-administration): every account, newest
 * first, paginated so none is unreachable.
 *
 * The role is required twice — here and by `access_control` on `^/admin(/|$)`
 * — the same doubling the owner pages use for `ROLE_USER` (design decision 4).
 */
#[IsGranted(User::ROLE_ADMIN)]
final class UserListController extends AbstractController
{
    public const int PER_PAGE = 30;

    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    #[Route('/admin/users', name: 'app_admin_users', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $total = $this->users->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->query->getInt('page', 1)));

        return $this->render('admin/users.html.twig', [
            'users' => $this->users->findPage(($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
