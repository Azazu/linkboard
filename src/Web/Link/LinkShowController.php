<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Auth\Entity\User;
use App\Link\Security\LinkVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** `/links/{id}` (FR-WEB-1): the link's details, its short URL with a copy control, and its QR code. */
#[IsGranted(User::ROLE_USER)]
final class LinkShowController extends AbstractController
{
    public function __construct(private readonly LinkPages $pages)
    {
    }

    #[Route('/links/{id}', name: 'app_link_show', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $link = $this->pages->findGranted($id, LinkVoter::VIEW);

        return $this->render('link/show.html.twig', [
            'link' => $link,
            'resource' => $this->pages->resource($link),
        ]);
    }
}
