<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\Shared\Api\CommonErrorResponses;
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
            foreach ($operation['responses'] ?? [] as $status => $response) {
                if ((int) $status < 400) {
                    continue;
                }
                $types = array_keys($response['content'] ?? []);
                self::assertSame(['application/problem+json'], $types, "$name answers $status");
                $properties = $response['content']['application/problem+json']['schema']['properties'] ?? [];
                foreach (['type', 'title', 'status', 'detail'] as $member) {
                    self::assertArrayHasKey($member, $properties, "$name $status carries $member");
                }
                self::assertArrayHasKey('example', $response['content']['application/problem+json'], "$name $status is shown with values");
            }
        }
    }

    public function testAValidationFailureIsDocumentedWithItsViolations(): void
    {
        $post = self::operations()['POST /api/v1/links'] ?? null;
        self::assertIsArray($post);
        $schema = $post['responses']['422']['content']['application/problem+json']['schema'] ?? [];
        $violation = $schema['properties']['violations']['items']['properties'] ?? [];

        self::assertArrayHasKey('propertyPath', $violation);
        self::assertArrayHasKey('message', $violation);
    }

    public function testTheGuardedOperationsDeclareThatTheyCanRefuseACredential(): void
    {
        $without = [];
        foreach (self::operations() as $name => $operation) {
            if (!isset($operation['responses']['401'])) {
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
            $refusal = $operation['responses']['429'] ?? null;
            self::assertIsArray($refusal, "$name declares 429: every documented operation is covered by a limiter");
            self::assertArrayHasKey('Retry-After', $refusal['headers'] ?? [], "$name names the delay");

            // the per-IP limiter on the authentication endpoints names only the
            // delay; the per-identity one also reports the allowance it left
            if (str_starts_with($name, 'POST /api/v1/auth/')) {
                continue;
            }
            foreach ($operation['responses'] as $status => $response) {
                if ((int) $status < 400) {
                    self::assertArrayHasKey('X-RateLimit-Remaining', $response['headers'] ?? [], "$name $status names the remaining allowance");
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
                $operation['parameters'] ?? [],
                static fn (array $parameter): bool => 'query' === ($parameter['in'] ?? null),
            );
            if ([] === $parameters) {
                continue;
            }
            if (!isset($operation['responses']['400']) && !isset($operation['responses']['422'])) {
                $silent[] = $name;
            }
        }

        self::assertSame([], $silent, 'every operation taking a query parameter declares how it refuses one');
    }

    public function testTheKeyCapIsDeclaredOnTheOneOperationThatAnswersIt(): void
    {
        $with = [];
        foreach (self::operations() as $name => $operation) {
            if (isset($operation['responses']['409'])) {
                $with[] = $name;
            }
        }

        self::assertSame(['POST /api/v1/api-keys'], $with);
    }

    public function testEveryPropertyOfEverySchemaCarriesAnExample(): void
    {
        $without = [];
        foreach (self::schemas() as $name => $definition) {
            foreach ($definition['properties'] ?? [] as $property => $shape) {
                if (!\array_key_exists('example', $shape)) {
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
            foreach ($definition['properties'] ?? [] as $property => $shape) {
                if (!\array_key_exists('example', $shape)) {
                    continue;
                }
                $example = $shape['example'];
                /** @var list<string> $types */
                $types = array_values((array) ($shape['type'] ?? []));
                if ([] !== $types && !self::accepts($types, $example)) {
                    $wrong[] = "$name.$property is ".get_debug_type($example).', declared '.implode('|', $types);
                }
                if (isset($shape['enum']) && !\in_array($example, $shape['enum'], true)) {
                    $wrong[] = "$name.$property is not one of its enum values";
                }
                $format = \is_string($shape['format'] ?? null) ? $shape['format'] : null;
                if (null !== $format && \is_string($example) && !self::matchesFormat($format, $example)) {
                    $wrong[] = "$name.$property is not a $format";
                }
            }
        }

        self::assertSame([], $wrong);
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
        $document = self::document();
        $schemas = [];
        foreach ($document['components']['schemas'] ?? [] as $name => $definition) {
            if (!\in_array($name, self::FRAMEWORK_SCHEMAS, true) && \is_array($definition)) {
                $schemas[$name] = $definition;
            }
        }
        foreach (self::operations() as $name => $operation) {
            foreach ($operation['requestBody']['content'] ?? [] as $type => $media) {
                if (isset($media['schema']['properties'])) {
                    $schemas["$name requestBody $type"] = $media['schema'];
                }
            }
            foreach ($operation['responses'] ?? [] as $status => $response) {
                foreach ($response['content'] ?? [] as $type => $media) {
                    if (isset($media['schema']['properties'])) {
                        $schemas["$name $status $type"] = $media['schema'];
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
            foreach (array_keys($operation['responses'] ?? []) as $status) {
                self::assertArrayHasKey($status, $after[$name]['responses'], "$name keeps its $status");
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
        $document = $normalizer->normalize($openApi, 'json');
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
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
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $document['paths'] ?? [];
        foreach ($paths as $path => $item) {
            foreach ($item as $method => $operation) {
                if (\in_array($method, ['get', 'post', 'patch', 'put', 'delete'], true) && \is_array($operation)) {
                    $operations[strtoupper($method).' '.$path] = $operation;
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
            $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array<string, mixed> $decoded */
            self::$document = $decoded;
        }

        return self::$document;
    }

    protected function tearDown(): void
    {
        self::$document = null;
        parent::tearDown();
    }
}
