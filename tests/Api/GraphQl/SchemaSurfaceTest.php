<?php

declare(strict_types=1);

namespace App\Tests\Api\GraphQl;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec graphql-api "Every API resource declares what it exposes to GraphQL".
 *
 * The list below is the surface. A resource added without a
 * `graphQlOperations` declaration does not quietly join it — it arrives with
 * the framework's default set, **mutations included**, and fails here
 * (change stretch-graphql, Gate 1 round 1, finding 1; confirmed by measuring
 * it: before the nine reports were declared, the schema carried
 * `createAdminSummaryReport`, `updateAdminSummaryReport` and
 * `deleteAdminSummaryReport` on read models).
 */
#[CoversNothing]
final class SchemaSurfaceTest extends KernelTestCase
{
    /**
     * Every query the schema is allowed to expose. `node` is the framework's
     * own Relay entry point, not a resource of ours.
     */
    private const array QUERIES = [
        'adminSummaryReport',
        'adminTimeseriesReport',
        'adminTopLinksReport',
        'link',
        'linkCountriesReport',
        'linkDevicesReport',
        'linkReferrersReport',
        'linkSummaryReport',
        'linkTimeseriesReport',
        'linkVariantsReport',
        'links',
        'me',
        'node',
    ];

    public function testTheSchemaExposesExactlyTheDeclaredQueries(): void
    {
        self::assertSame(self::QUERIES, $this->queryNames(), 'a query appeared or vanished; declare it or remove it, do not edit this list to match');
    }

    public function testTheSchemaHasNoMutationType(): void
    {
        self::assertNull($this->schema()->getMutationType(), 'this API writes through REST only');
    }

    public function testNoExcludedResourceHasATypeInTheSchema(): void
    {
        $types = array_keys($this->schema()->getTypeMap());
        foreach (['AdminUser', 'Registration', 'ApiKey'] as $excluded) {
            foreach ($types as $type) {
                self::assertStringNotContainsString($excluded, $type, "$type derives from an excluded resource");
            }
        }
    }

    public function testEveryApiResourceDeclaresItsGraphQlOperations(): void
    {
        // the guard the requirement asks for: silence is not exclusion, so a
        // class that says nothing must fail here rather than in production
        $names = self::getContainer()->get(ResourceNameCollectionFactoryInterface::class);
        self::assertInstanceOf(ResourceNameCollectionFactoryInterface::class, $names);
        $metadata = self::getContainer()->get(ResourceMetadataCollectionFactoryInterface::class);
        self::assertInstanceOf(ResourceMetadataCollectionFactoryInterface::class, $metadata);

        $undeclared = [];
        $seen = 0;
        foreach ($names->create() as $class) {
            ++$seen;
            foreach ($metadata->create($class) as $resource) {
                if (null === $resource->getGraphQlOperations()) {
                    $undeclared[] = $class;
                }
            }
        }

        self::assertGreaterThan(10, $seen, 'no resource was inspected, so this proves nothing');
        self::assertSame([], $undeclared, 'these resources declare no graphQlOperations, so they receive the default set with its three mutations');
    }

    /**
     * @return list<string>
     */
    private function queryNames(): array
    {
        $names = array_keys($this->schema()->getQueryType()?->getFields() ?? []);
        sort($names);

        return $names;
    }

    private function schema(): \GraphQL\Type\Schema
    {
        $builder = self::getContainer()->get('api_platform.graphql.schema_builder');
        self::assertInstanceOf(\ApiPlatform\GraphQl\Type\SchemaBuilderInterface::class, $builder);

        return $builder->getSchema();
    }
}
