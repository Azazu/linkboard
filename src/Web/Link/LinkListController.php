<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Auth\Entity\User;
use App\Link\LinkListQuery;
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

        $state = $request->query->get('state', '');
        $slug = trim((string) $request->query->get('slug', ''));
        $order = $request->query->get('order', 'createdAt');
        $direction = 'asc' === $request->query->get('direction') ? 'asc' : 'desc';
        if (!\in_array($order, LinkListQuery::ORDER_FIELDS, true)) {
            $order = 'createdAt';
        }

        $query = new LinkListQuery(
            isActive: match ($state) {
                'active' => true, 'inactive' => false, default => null
            },
            slugContains: '' === $slug ? null : $slug,
            orderField: $order,
            direction: $direction,
        );

        $total = $this->links->countForOwner($user->getId(), $query);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->query->getInt('page', 1)));

        return $this->render('link/index.html.twig', [
            'links' => $this->links->findPageForOwner($user->getId(), $query, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => ['state' => $state, 'slug' => $slug, 'order' => $order, 'direction' => $direction],
        ]);
    }
}
