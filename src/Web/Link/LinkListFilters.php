<?php

declare(strict_types=1);

namespace App\Web\Link;

use App\Link\LinkListQuery;
use Symfony\Component\HttpFoundation\Request;

/**
 * The filter and ordering controls of a link listing, read from the query
 * string. One implementation for the owner's `/links` and the administrative
 * `/admin/links` (design decision 6 of add-web-admin-and-stats): the two pages
 * behave identically because they are the same code, and an unknown value
 * falls back to the default rather than refusing the page — these are filters,
 * not a report's parameters.
 */
final readonly class LinkListFilters
{
    /**
     * @param array<string, string> $values what the controls should show back
     */
    private function __construct(
        public LinkListQuery $query,
        public array $values,
    ) {
    }

    public static function from(Request $request): self
    {
        $state = (string) $request->query->get('state', '');
        $slug = trim((string) $request->query->get('slug', ''));
        $order = (string) $request->query->get('order', 'createdAt');
        $direction = 'asc' === $request->query->get('direction') ? 'asc' : 'desc';
        if (!\in_array($order, LinkListQuery::ORDER_FIELDS, true)) {
            $order = 'createdAt';
        }
        if (!\in_array($state, ['active', 'inactive'], true)) {
            $state = '';
        }

        return new self(
            new LinkListQuery(
                isActive: match ($state) {
                    'active' => true,
                    'inactive' => false,
                    default => null,
                },
                slugContains: '' === $slug ? null : $slug,
                orderField: $order,
                direction: $direction,
            ),
            ['state' => $state, 'slug' => $slug, 'order' => $order, 'direction' => $direction],
        );
    }
}
