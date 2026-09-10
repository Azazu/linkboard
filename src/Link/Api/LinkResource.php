<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Link\Entity\Link;

/**
 * The link as the API sees it (specification §2.2, §4). A DTO: the entity
 * stays a plain DataMapper object; providers/processors translate.
 */
#[ApiResource(
    shortName: 'Link',
    // nullable fields (utm, rules, expiresAt, maxClicks) are part of the contract: emit them as null
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/links',
            input: CreateLinkInput::class,
            processor: CreateLinkProcessor::class,
            security: 'is_granted("ROLE_USER")',
            status: 201,
            description: 'Create a link. Without `slug` a 7-character slug is generated.',
        ),
        new GetCollection(
            uriTemplate: '/links',
            provider: OwnLinksProvider::class,
            security: 'is_granted("ROLE_USER")',
            paginationItemsPerPage: 30,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            description: 'The caller\'s links, newest first.',
            openapi: new OpenApiOperation(parameters: [
                new Parameter(name: 'isActive', in: 'query', description: 'true or false', schema: ['type' => 'boolean']),
                new Parameter(name: 'slug', in: 'query', description: 'case-sensitive substring', schema: ['type' => 'string']),
                new Parameter(name: 'order[createdAt]', in: 'query', schema: ['type' => 'string', 'enum' => ['asc', 'desc']]),
                new Parameter(name: 'order[clickCount]', in: 'query', schema: ['type' => 'string', 'enum' => ['asc', 'desc']]),
            ]),
        ),
        new Get(
            uriTemplate: '/links/{id}',
            provider: LinkItemProvider::class,
            security: 'is_granted("LINK_VIEW", object)',
        ),
        new Patch(
            uriTemplate: '/links/{id}',
            input: UpdateLinkInput::class,
            provider: LinkItemProvider::class,
            processor: UpdateLinkProcessor::class,
            security: 'is_granted("LINK_EDIT", object)',
            description: 'Merge patch: only the fields present in the body change. `expiresAt`, `maxClicks`, `utm` and `rules` may be cleared with null (a `rules` document replaces the stored one whole); `targetUrl` and `isActive` may not be null; `slug` is immutable.',
        ),
        new Delete(
            uriTemplate: '/links/{id}',
            provider: LinkItemProvider::class,
            processor: DeleteLinkProcessor::class,
            security: 'is_granted("LINK_DELETE", object)',
            description: 'Hard delete; the slug becomes available again.',
        ),
        new GetCollection(
            uriTemplate: '/admin/links',
            provider: AllLinksProvider::class,
            security: 'is_granted("ROLE_ADMIN")',
            paginationItemsPerPage: 30,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            description: 'Every user\'s links (admin).',
            openapi: new OpenApiOperation(parameters: [
                new Parameter(name: 'isActive', in: 'query', schema: ['type' => 'boolean']),
                new Parameter(name: 'slug', in: 'query', schema: ['type' => 'string']),
                new Parameter(name: 'order[createdAt]', in: 'query', schema: ['type' => 'string', 'enum' => ['asc', 'desc']]),
                new Parameter(name: 'order[clickCount]', in: 'query', schema: ['type' => 'string', 'enum' => ['asc', 'desc']]),
            ]),
        ),
    ],
)]
final readonly class LinkResource
{
    /**
     * @param array<string, string>|null $utm
     * @param array<string, mixed>|null  $rules
     */
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $slug,
        public string $shortUrl,
        public string $targetUrl,
        public ?array $utm,
        #[ApiProperty(description: 'Routing-rules document (version 1; schema: docs/reference/rules-schema.json in the repository) or null for a plain redirect.', openapiContext: ['type' => 'object', 'nullable' => true])]
        public ?array $rules,
        public ?\DateTimeImmutable $expiresAt,
        public ?int $maxClicks,
        public int $clickCount,
        public bool $isActive,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromLink(Link $link, string $publicBaseUrl): self
    {
        return new self(
            (string) $link->getId(),
            (string) $link->getOwner()->getId(),
            $link->getSlug(),
            rtrim($publicBaseUrl, '/').'/'.$link->getSlug(),
            $link->getTargetUrl(),
            $link->getUtm(),
            $link->getRules(),
            $link->getExpiresAt(),
            $link->getMaxClicks(),
            $link->getClickCount(),
            $link->isActive(),
            $link->getCreatedAt(),
            $link->getUpdatedAt(),
        );
    }
}
