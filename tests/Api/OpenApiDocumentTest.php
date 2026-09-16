<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\Shared\Api\CommonErrorResponses;
use App\Tests\Support\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Spec api-docs: "Every operation documents the statuses it can answer",
 * "Errors are documented as problem details", "Operations carry examples" and
 * "The rate-limited operations document their headers".
 *
 * These assert the document's own claims. That those claims match what the API
 * answers is `tests/Api/Contract`.
 */
#[CoversNothing]
final class OpenApiDocumentTest extends WebTestCase
{
    /** API Platform's own error schemas: not this project's to annotate. */
    private const array FRAMEWORK_SCHEMAS = ['Error', 'ConstraintViolation'];

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    public function testEveryErrorResponseIsProblemDetailsAlone(): void
    {
        foreach (self::operations() as $name => $operation) {
            $responses = Json::mapAt($operation, 'responses');
            foreach (array_keys($responses) as $status) {
                if ((int) $status < 400) {
                    continue;
                }
                $media = Json::mapAt($responses, $status, 'content');
                self::assertSame(['application/problem+json'], array_keys($media), "$name answers $status");
                $properties = Json::mapAt($media, 'application/problem+json', 'schema', 'properties');
                foreach (['type', 'title', 'status', 'detail'] as $member) {
                    self::assertArrayHasKey($member, $properties, "$name $status carries $member");
                }
                self::assertArrayHasKey('example', Json::map($media, 'application/problem+json'), "$name $status is shown with values");
            }
        }
    }

    public function testAValidationFailureIsDocumentedWithItsViolations(): void
    {
        $operations = self::operations();
        self::assertArrayHasKey('POST /api/v1/links', $operations);
        $violation = Json::mapAt(
            $operations['POST /api/v1/links'],
            'responses', '422', 'content', 'application/problem+json', 'schema',
            'properties', 'violations', 'items', 'properties',
        );

        self::assertArrayHasKey('propertyPath', $violation);
        self::assertArrayHasKey('message', $violation);
    }

    public function testTheGuardedOperationsDeclareThatTheyCanRefuseACredential(): void
    {
        $without = [];
        foreach (self::operations() as $name => $operation) {
            if (!Json::hasAt($operation, 'responses', '401')) {
                $without[] = $name;
            }
        }

        // registration is the one operation that neither requires a credential
        // nor checks one; everything else can answer 401
        self::assertSame(['POST /api/v1/auth/register'], $without);
    }

    public function testEveryRateLimitedOperationDeclaresItsRefusalAndItsHeaders(): void
    {
        foreach (self::operations() as $name => $operation) {
            self::assertTrue(Json::hasAt($operation, 'responses', '429'), "$name declares 429: every documented operation is covered by a limiter");
            self::assertArrayHasKey('Retry-After', Json::mapAt($operation, 'responses', '429', 'headers'), "$name names the delay");

            // the per-IP limiter on the authentication endpoints names only the
            // delay; the per-identity one also reports the allowance it left
            if (str_starts_with($name, 'POST /api/v1/auth/')) {
                continue;
            }
            $responses = Json::mapAt($operation, 'responses');
            foreach (array_keys($responses) as $status) {
                if ((int) $status < 400) {
                    self::assertArrayHasKey('X-RateLimit-Remaining', Json::mapAt($responses, $status, 'headers'), "$name $status names the remaining allowance");
                }
            }
        }
    }

    public function testAnOperationThatTakesQueryParametersSaysHowItRefusesOne(): void
    {
        // an operation with parameters can be given a value it will not take;
        // which status it answers with is its own (the framework's 400 for a
        // collection, the analytics rules' 422 for a report), but it must say
        // one of them (Gate 2 round 2, finding 1)
        $silent = [];
        foreach (self::operations() as $name => $operation) {
            $parameters = array_filter(
                Json::objectsAt($operation, 'parameters'),
                static fn (array $parameter): bool => 'query' === ($parameter['in'] ?? null),
            );
            if ([] === $parameters) {
                continue;
            }
            if (!Json::hasAt($operation, 'responses', '400') && !Json::hasAt($operation, 'responses', '422')) {
                $silent[] = $name;
            }
        }

        self::assertSame([], $silent, 'every operation taking a query parameter declares how it refuses one');
    }

    public function testTheKeyCapIsDeclaredOnTheOneOperationThatAnswersIt(): void
    {
        $with = [];
        foreach (self::operations() as $name => $operation) {
            if (Json::hasAt($operation, 'responses', '409')) {
                $with[] = $name;
            }
        }

        self::assertSame(['POST /api/v1/api-keys'], $with);
    }

    public function testEveryPropertyOfEverySchemaCarriesAnExample(): void
    {
        $without = [];
        foreach (self::schemas() as $name => $definition) {
            $properties = Json::mapAt($definition, 'properties');
            foreach (array_keys($properties) as $property) {
                if (!\array_key_exists('example', Json::map($properties, $property))) {
                    $without[] = "$name.$property";
                }
            }
        }

        self::assertSame([], $without, 'every property is shown with a value');
    }

    public function testTheInlineSchemasAreCoveredToo(): void
    {
        // the JWT bundle generates the token operation's payloads inline, with
        // no property of ours to annotate: the walk above must reach them, or
        // it reports success over an empty skeleton (Gate 2 round 1, finding 4)
        $names = array_keys(self::schemas());

        self::assertContains('POST /api/v1/auth/token requestBody application/json', $names);
        self::assertContains('POST /api/v1/auth/token 200 application/json', $names);
    }

    public function testTheFrameworksOwnErrorSchemasAreUnreferenced(): void
    {
        // they are API Platform's, not this project's, so they carry no
        // examples of ours — and after the decorator narrows every error
        // response to its own schema, nothing points at them any more
        $document = self::document();
        $encoded = json_encode($document, \JSON_THROW_ON_ERROR);
        foreach (self::FRAMEWORK_SCHEMAS as $schema) {
            self::assertStringNotContainsString('#/components/schemas/'.$schema.'"', $encoded, $schema.' is not referenced');
        }
    }

    public function testEveryExampleIsAValueItsOwnSchemaAccepts(): void
    {
        $wrong = [];
        foreach (self::schemas() as $name => $definition) {
            $properties = Json::mapAt($definition, 'properties');
            foreach (array_keys($properties) as $property) {
                $shape = Json::map($properties, $property);
                if (!\array_key_exists('example', $shape)) {
                    continue;
                }
                $example = $shape['example'];
                $types = self::declaredTypes($shape['type'] ?? null);
                if ([] !== $types && !self::accepts($types, $example)) {
                    $wrong[] = "$name.$property is ".get_debug_type($example).', declared '.implode('|', $types);
                }
                if (Json::hasAt($shape, 'enum') && !\in_array($example, Json::listAt($shape, 'enum'), true)) {
                    $wrong[] = "$name.$property is not one of its enum values";
                }
                $format = $shape['format'] ?? null;
                if (\is_string($format) && \is_string($example) && !self::matchesFormat($format, $example)) {
                    $wrong[] = "$name.$property is not a $format";
                }
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * A schema's `type`, which OpenAPI allows as a single name or a list of
     * them; anything else is no declaration at all.
     *
     * @return list<string>
     */
    private static function declaredTypes(mixed $declared): array
    {
        $types = [];
        foreach (\is_array($declared) ? $declared : [$declared] as $type) {
            if (\is_string($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    private static function matchesFormat(string $format, string $example): bool
    {
        return match ($format) {
            'date-time' => false !== \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $example),
            'date' => 1 === preg_match('/^\d{4}-\d\d-\d\d$/', $example),
            'email' => false !== filter_var($example, \FILTER_VALIDATE_EMAIL),
            'uri', 'iri-reference' => false !== filter_var($example, \FILTER_VALIDATE_URL),
            'uuid' => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $example),
            default => true,
        };
    }

    /**
     * Every schema the document defines: the named ones, and the ones written
     * inline on an operation's request body or response.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function schemas(): array
    {
        $schemas = [];
        $defined = Json::mapAt(self::document(), 'components', 'schemas');
        foreach (array_keys($defined) as $name) {
            if (!\in_array($name, self::FRAMEWORK_SCHEMAS, true)) {
                $schemas[$name] = Json::map($defined, $name);
            }
        }
        foreach (self::operations() as $name => $operation) {
            $requestContent = Json::mapAt($operation, 'requestBody', 'content');
            foreach (array_keys($requestContent) as $type) {
                if (Json::hasAt($requestContent, $type, 'schema', 'properties')) {
                    $schemas["$name requestBody $type"] = Json::mapAt($requestContent, $type, 'schema');
                }
            }
            $responses = Json::mapAt($operation, 'responses');
            foreach (array_keys($responses) as $status) {
                $responseContent = Json::mapAt($responses, $status, 'content');
                foreach (array_keys($responseContent) as $type) {
                    if (Json::hasAt($responseContent, $type, 'schema', 'properties')) {
                        $schemas["$name $status $type"] = Json::mapAt($responseContent, $type, 'schema');
                    }
                }
            }
        }

        return $schemas;
    }

    public function testTheDecoratorOnlyAddsAndNarrows(): void
    {
        // the document first: it boots the client, and the kernel boots once
        $after = self::operations();
        // the inner factory is not a public service: reach it through the
        // decorator itself and compare what it produced with what shipped
        $undecorated = self::undecorated();
        self::assertInstanceOf(OpenApiFactoryInterface::class, $undecorated);
        $before = self::indexed(self::normalize($undecorated()));

        self::assertSame(array_keys($before), array_keys($after), 'no operation is added or lost');
        foreach ($before as $name => $operation) {
            foreach (array_keys(Json::mapAt($operation, 'responses')) as $status) {
                self::assertArrayHasKey($status, Json::mapAt($after[$name], 'responses'), "$name keeps its $status");
            }
        }
    }

    /**
     * @param list<string> $types
     */
    private static function accepts(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'string' => \is_string($value),
                'integer' => \is_int($value),
                'number' => \is_int($value) || \is_float($value),
                'boolean' => \is_bool($value),
                'array' => \is_array($value),
                'object' => \is_array($value) || \is_object($value),
                'null' => null === $value,
                default => true,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private static function undecorated(): OpenApiFactoryInterface
    {
        $decorated = self::getContainer()->get('api_platform.openapi.factory');
        self::assertInstanceOf(CommonErrorResponses::class, $decorated);
        $inner = new \ReflectionProperty(CommonErrorResponses::class, 'inner')->getValue($decorated);
        self::assertInstanceOf(OpenApiFactoryInterface::class, $inner);

        return $inner;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalize(object $openApi): array
    {
        $normalizer = self::getContainer()->get('serializer');
        self::assertInstanceOf(NormalizerInterface::class, $normalizer);

        return Json::asMap($normalizer->normalize($openApi, 'json'), 'the normalized document');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function operations(): array
    {
        return self::indexed(self::document());
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, mixed>>
     */
    private static function indexed(array $document): array
    {
        $operations = [];
        $paths = Json::mapAt($document, 'paths');
        foreach (array_keys($paths) as $path) {
            $item = Json::map($paths, $path);
            foreach (array_keys($item) as $method) {
                if (\in_array($method, ['get', 'post', 'patch', 'put', 'delete'], true)) {
                    $operations[strtoupper($method).' '.$path] = Json::map($item, $method);
                }
            }
        }
        ksort($operations);

        return $operations;
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        if (null === self::$document) {
            $client = self::createClient();
            $client->request('GET', '/api/docs.json');
            self::assertResponseIsSuccessful();
            self::$document = Json::decode($client->getResponse()->getContent());
        }

        return self::$document;
    }

    protected function tearDown(): void
    {
        self::$document = null;
        parent::tearDown();
    }
}
