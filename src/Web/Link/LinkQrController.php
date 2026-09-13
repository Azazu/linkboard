<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Auth\Entity\User;
use App\Link\Qr\LinkQrProcessor;
use App\Link\Qr\QrCodeRenderer;
use App\Link\Qr\QrFormat;
use App\Link\Security\LinkVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The QR code the link page shows and offers for download. The same renderer,
 * format vocabulary and caching header as the API operation (spec qr-codes);
 * only the authorization answer differs, as everywhere in the UI: 404 rather
 * than 403 for a link that is not the signed-in user's.
 */
#[IsGranted(User::ROLE_USER)]
final class LinkQrController extends AbstractController
{
    public function __construct(
        private readonly LinkPages $pages,
        private readonly QrCodeRenderer $renderer,
    ) {
    }

    #[Route('/links/{id}/qr', name: 'app_link_qr', requirements: ['id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function __invoke(string $id, Request $request): Response
    {
        $link = $this->pages->findGranted($id, LinkVoter::VIEW);
        $resource = $this->pages->resource($link);
        $format = QrFormat::fromQuery($request->query->getString('format') ?: null);

        $response = new Response($this->renderer->render($resource->shortUrl, $format));
        $response->headers->set('Content-Type', $format->mimeType());
        $response->headers->set('Content-Disposition', \sprintf('inline; filename="%s.%s"', $link->getSlug(), $format->extension()));
        $response->headers->set('Cache-Control', LinkQrProcessor::CACHE_CONTROL);

        return $response;
    }
}
