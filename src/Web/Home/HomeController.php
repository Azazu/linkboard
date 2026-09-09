<?php

declare(strict_types=1);

namespace App\Web\Home;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Post-login landing page. A placeholder until the web UI change adds the
 * dashboard; kept so the login flow has a target.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig');
    }
}
