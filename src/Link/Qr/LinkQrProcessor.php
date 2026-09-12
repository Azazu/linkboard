<?php

declare(strict_types=1);

namespace App\Link\Qr;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Link\Api\LinkResource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/links/{id}/qr — renders the link's QR code (spec qr-codes).
 *
 * Runs inside API Platform's own pipeline (design decision 1): the item
 * provider has loaded the link (404 first), the voter has passed LINK_VIEW,
 * the declared `format` parameter has been validated. `write: true` on the
 * operation is what makes WriteProcessor hand the resource to this processor
 * on a GET; nothing is written. The Response passes through the serialize and
 * respond processors untouched.
 *
 * @implements ProcessorInterface<LinkResource, Response>
 */
final readonly class LinkQrProcessor implements ProcessorInterface
{
    public const string CACHE_CONTROL = 'private, max-age=86400';

    public function __construct(private QrCodeRenderer $renderer)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        if (!$data instanceof LinkResource) {
            throw new \LogicException('The QR operation expects the link resource from its provider.');
        }
        $request = $context['request'] ?? null;
        $requested = $request instanceof Request ? $request->query->get('format') : null;
        $format = QrFormat::fromQuery(\is_string($requested) ? $requested : null);

        $response = new Response($this->renderer->render($data->shortUrl, $format), Response::HTTP_OK, [
            'Content-Type' => $format->mimeType(),
            // the slug is ^[A-Za-z0-9_-]{3,32}$ (spec links "Slug rules"): safe in a quoted filename as is
            'Content-Disposition' => \sprintf('inline; filename="%s.%s"', $data->slug, $format->extension()),
        ]);
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);

        return $response;
    }
}
