<?php

declare(strict_types=1);

namespace App\Link\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\GraphQl\Query as QueryOperation;
use ApiPlatform\Metadata\GraphQl\QueryCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Link\Entity\Link;
use App\Link\Qr\LinkQrProcessor;
use App\Shared\Api\RefusedParameters;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The link as the API sees it (specification §2.2, §4). A DTO: the entity
 * stays a plain DataMapper object; providers/processors translate.
 */
#[ApiResource(
    shortName: 'Link',
    // nullable fields (utm, rules, expiresAt, maxClicks) are part of the contract: emit them as null
    normalizationContext: ['skip_null_values' => false],
    // Read-only through GraphQL (change stretch-graphql): the same providers
    // and the same voter as the REST operations, listed explicitly because a
    // resource that declares none receives the default set with three
    // mutations. Writing stays REST's.
    graphQlOperations: [
        new QueryOperation(
            provider: LinkItemProvider::class,
            security: 'is_granted("LINK_VIEW", object)',
            description: 'One link the caller may view.',
        ),
        new QueryCollection(
            provider: OwnLinksProvider::class,
            security: 'is_granted("ROLE_USER")',
            paginationItemsPerPage: 30,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            description: 'The caller\'s links, newest first.',
        ),
    ],
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
            openapi: new OpenApiOperation(responses: [400 => new OpenApiResponse(RefusedParameters::BAD_REQUEST)], parameters: [
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
        // The QR code of the short URL (spec qr-codes). The item provider and the
        // voter run as for GET /links/{id}; `write: true` makes API Platform hand
        // the resource to the processor on a GET (design decision 1) — nothing is
        // written. `format` selects the image; `Accept` only gates (406 for a
        // client that admits neither image type).
        new Get(
            uriTemplate: '/links/{id}/qr',
            name: 'link_qr',
            provider: LinkItemProvider::class,
            processor: LinkQrProcessor::class,
            write: true,
            security: 'is_granted("LINK_VIEW", object)',
            outputFormats: ['svg' => ['image/svg+xml'], 'png' => ['image/png']],
            parameters: [
                'format' => new QueryParameter(
                    key: 'format',
                    schema: ['type' => 'string', 'enum' => ['svg', 'png'], 'default' => 'svg'],
                    description: 'Image format: `svg` (default) or `png` (512 × 512 px). Selects the image regardless of `Accept`.',
                    constraints: [new Assert\Choice(choices: ['svg', 'png'], message: 'format must be svg or png.')],
                ),
            ],
            description: 'The QR code of the link\'s short URL as SVG (default) or PNG (`?format=png`, 512 × 512 px); `Content-Disposition: inline; filename="<slug>.svg|png"`, `Cache-Control: private, max-age=86400`. Owner or admin.',
            // `format` is constrained, so a value outside it is a violation
            // (spec qr-codes), not the framework's 400
            openapi: new OpenApiOperation(responses: [422 => new OpenApiResponse('`format` is neither `svg` nor `png`.')]),
        ),
        new GetCollection(
            uriTemplate: '/admin/links',
            provider: AllLinksProvider::class,
            security: 'is_granted("ROLE_ADMIN")',
            paginationItemsPerPage: 30,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            description: 'Every user\'s links (admin).',
            openapi: new OpenApiOperation(responses: [400 => new OpenApiResponse(RefusedParameters::BAD_REQUEST)], parameters: [
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
        #[ApiProperty(example: '01920f3a-6f2e-7a1c-9c0d-2b4e8a1d3f57')]
        public string $id,
        #[ApiProperty(example: '01920f3a-1111-7a1c-9c0d-2b4e8a1d3f57')]
        public string $ownerId,
        #[ApiProperty(example: 'spring-sale')]
        public string $slug,
        #[ApiProperty(example: 'https://lnk.example.com/spring-sale')]
        public string $shortUrl,
        #[ApiProperty(example: 'https://example.com/products/spring?ref=newsletter')]
        public string $targetUrl,
        #[ApiProperty(example: ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'spring'])]
        public ?array $utm,
        #[ApiProperty(
            description: 'Routing-rules document (version 1; schema: docs/reference/rules-schema.json in the repository) or null for a plain redirect.',
            openapiContext: ['type' => 'object', 'nullable' => true],
            example: ['version' => 1, 'rules' => [['match' => ['country' => ['DE', 'AT']], 'target' => 'https://example.de/fruehling']]],
        )]
        public ?array $rules,
        #[ApiProperty(example: '2026-12-31T23:59:59+00:00')]
        public ?\DateTimeImmutable $expiresAt,
        #[ApiProperty(example: 10000)]
        public ?int $maxClicks,
        #[ApiProperty(example: 1842)]
        public int $clickCount,
        #[ApiProperty(example: true)]
        public bool $isActive,
        #[ApiProperty(example: '2026-09-01T08:15:00+00:00')]
        public \DateTimeImmutable $createdAt,
        #[ApiProperty(example: '2026-09-12T11:40:00+00:00')]
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
