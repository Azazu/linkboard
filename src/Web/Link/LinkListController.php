<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Auth\Entity\User;
use App\Link\LinkRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `/links` (FR-WEB-1): the signed-in user's links, filtered and ordered by the
 * same vocabulary the API's collection uses, paginated. The query is scoped by
 * owner in SQL, so no row of anybody else's can reach the page.
 *
 * The controls themselves are `LinkListFilters`, shared with `/admin/links`.
 */
#[IsGranted(User::ROLE_USER)]
final class LinkListController extends AbstractController
{
    public const int PER_PAGE = 30;

    public function __construct(private readonly LinkRepositoryInterface $links)
    {
    }

    #[Route('/links', name: 'app_links', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $filters = LinkListFilters::from($request);
        $total = $this->links->countForOwner($user->getId(), $filters->query);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->query->getInt('page', 1)));

        return $this->render('link/index.html.twig', [
            'links' => $this->links->findPageForOwner($user->getId(), $filters->query, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters->values,
        ]);
    }
}
