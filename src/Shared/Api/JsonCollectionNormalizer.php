<?php

declare(strict_types=1);

namespace App\Shared\Api;

use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\Pagination\PartialPaginatorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Pagination envelope for the plain `json` format (specification §4):
 * {"items": [...], "totalItems", "page", "itemsPerPage"}. API Platform ships
 * this envelope only for JSON-LD/Hydra and JSON:API, which are disabled here.
 */
final class JsonCollectionNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param array<string, mixed> $context
     *
     * @return array{items: list<mixed>, page: int, itemsPerPage: int, totalItems?: int}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof PartialPaginatorInterface);

        $items = [];
        foreach ($data as $item) {
            $items[] = $this->normalizer->normalize($item, $format, $context);
        }

        $envelope = [
            'items' => $items,
            'page' => (int) $data->getCurrentPage(),
            'itemsPerPage' => (int) $data->getItemsPerPage(),
        ];
        if ($data instanceof PaginatorInterface) {
            $envelope['totalItems'] = (int) $data->getTotalItems();
        }

        return $envelope;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return 'json' === $format && $data instanceof PartialPaginatorInterface;
    }

    public function getSupportedTypes(?string $format): array
    {
        return 'json' === $format ? [PartialPaginatorInterface::class => true] : [];
    }
}
