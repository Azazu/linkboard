<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Web\Link\LinkListFilters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `/admin/links` (FR-WEB-1): every user's links, with the filters and ordering
 * the owner's own list offers — the same `LinkListFilters`, so the two pages
 * cannot drift apart (design decision 6).
 *
 * Each row names its owner. The addresses come from one batch lookup of the
 * identifiers on the page, never one query per row: a listing that lazy-loads
 * an association in a loop is exactly what the layout rule forbids.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class LinkListController extends AbstractController
{
    public const int PER_PAGE = 30;

    public function __construct(
        private readonly LinkRepositoryInterface $links,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    #[Route('/admin/links', name: 'app_admin_links', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $filters = LinkListFilters::from($request);
        $total = $this->links->count($filters->query);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $request->query->getInt('page', 1)));
        $links = $this->links->findPage($filters->query, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return $this->render('admin/links.html.twig', [
            'links' => $links,
            'owners' => $this->ownersOf($links),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters->values,
        ]);
    }

    /**
     * @param list<Link> $links
     *
     * @return array<string, string> owner identifier => address
     */
    private function ownersOf(array $links): array
    {
        // the identifier of a lazy association is known without loading it, so
        // this collects ids without touching the database, then asks once
        $ids = array_values(array_unique(array_map(static fn (Link $link): string => (string) $link->getOwner()->getId(), $links)));

        return array_map(
            static fn (User $user): string => $user->getEmail(),
            $this->users->findByIds(array_map(\Symfony\Component\Uid\Uuid::fromString(...), $ids)),
        );
    }
}
