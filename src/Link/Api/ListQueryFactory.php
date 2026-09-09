<?php

declare(strict_types=1);

namespace App\Link\Api;

use App\Link\LinkListQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Query parameters → LinkListQuery. Anything unsupported is a 400.
 */
final class ListQueryFactory
{
    public static function fromRequest(?Request $request): LinkListQuery
    {
        if (null === $request) {
            return new LinkListQuery();
        }
        $isActive = null;
        if ($request->query->has('isActive')) {
            $raw = $request->query->get('isActive');
            $isActive = match ($raw) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw new BadRequestHttpException('isActive must be true or false.'),
            };
        }
        $slug = $request->query->get('slug');
        if (null !== $slug && !\is_string($slug)) {
            throw new BadRequestHttpException('slug must be a string.');
        }

        $orderField = 'createdAt';
        $direction = 'desc';
        $order = $request->query->all('order');
        if ([] !== $order) {
            if (1 !== \count($order)) {
                throw new BadRequestHttpException('Order by one field only.');
            }
            $orderField = (string) array_key_first($order);
            $direction = strtolower((string) reset($order));
            if (!\in_array($orderField, LinkListQuery::ORDER_FIELDS, true) || !\in_array($direction, LinkListQuery::DIRECTIONS, true)) {
                throw new BadRequestHttpException('order accepts order[createdAt] or order[clickCount] with asc or desc.');
            }
        }

        return new LinkListQuery($isActive, $slug, $orderField, $direction);
    }
}
