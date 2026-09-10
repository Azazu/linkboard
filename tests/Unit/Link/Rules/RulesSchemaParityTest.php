<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link\Rules;

use App\Link\Rules\RulesDocument;
use App\Link\Rules\RulesDocumentParser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Spec routing-rules "Published schema matches the validator" (design
 * decision 3): every enumeration, limit, pattern and type of
 * docs/reference/rules-schema.json equals the parser's constants; every object
 * schema rejects unknown keys; the shared example document is accepted and is
 * the one the how-to shows.
 */
#[CoversNothing]
final class RulesSchemaParityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $decoded = json_decode((string) file_get_contents(self::root().'/docs/reference/rules-schema.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $this->schema = $decoded;
    }

    public function testTopLevelShape(): void
    {
        $s = $this->schema;
        self::assertSame('object', $s['type']);
        self::assertFalse($s['additionalProperties']);
        self::assertSame(['version'], $s['required']);
        self::assertSame([['required' => ['rules']], ['required' => ['variants']]], $s['anyOf']);
        self::assertSame(RulesDocument::VERSION, $s['properties']['version']['const']);
        self::assertSame(['type' => 'array', 'minItems' => RulesDocument::MIN_RULES, 'maxItems' => RulesDocument::MAX_RULES, 'items' => ['$ref' => '#/$defs/rule']], $s['properties']['rules']);
        self::assertSame(['type' => 'array', 'minItems' => RulesDocument::MIN_VARIANTS, 'maxItems' => RulesDocument::MAX_VARIANTS, 'items' => ['$ref' => '#/$defs/variant']], $s['properties']['variants']);
        self::assertSame(['version', 'rules', 'variants'], array_keys($s['properties']));
    }

    public function testValueListsMatchTheVocabulariesAndLimits(): void
    {
        $defs = $this->schema['$defs'];
        foreach (['deviceValues', 'osValues', 'countryValues', 'languageValues'] as $name) {
            self::assertSame('array', $defs[$name]['type'], $name);
            self::assertSame(RulesDocument::MIN_VALUES, $defs[$name]['minItems'], $name);
            self::assertSame(RulesDocument::MAX_VALUES, $defs[$name]['maxItems'], $name);
            self::assertTrue($defs[$name]['uniqueItems'], $name);
            self::assertSame('string', $defs[$name]['items']['type'], $name);
        }
        self::assertSame(RulesDocument::DEVICES, $defs['deviceValues']['items']['enum']);
        self::assertSame(RulesDocument::OSES, $defs['osValues']['items']['enum']);
        self::assertSame(self::ecma(RulesDocument::COUNTRY_PATTERN), $defs['countryValues']['items']['pattern']);
        self::assertSame(self::ecma(RulesDocument::LANGUAGE_PATTERN), $defs['languageValues']['items']['pattern']);
        self::assertSame(RulesDocument::MATCH_KEYS, array_keys($defs['match']['properties']));
        self::assertCount(3, $defs['match']['oneOf'], 'exactly one dimension: device (device and/or os), country, language');
    }

    public function testRuleAndVariantShapes(): void
    {
        $defs = $this->schema['$defs'];
        self::assertSame(['match', 'target'], $defs['rule']['required']);
        self::assertSame(['name', 'weight', 'target'], $defs['variant']['required']);
        self::assertSame(['type' => 'string', 'pattern' => self::ecma(RulesDocument::NAME_PATTERN)], $defs['variant']['properties']['name']);
        self::assertSame(['type' => 'integer', 'minimum' => RulesDocument::MIN_WEIGHT], $defs['variant']['properties']['weight']);
        self::assertSame('string', $defs['target']['type']);
        self::assertSame(RulesDocument::TARGET_MAX_LENGTH, $defs['target']['maxLength']);
        self::assertStringContainsString((string) RulesDocument::WEIGHT_SUM, $this->schema['description']);
    }

    public function testEveryObjectSchemaRejectsUnknownKeys(): void
    {
        $objects = 0;
        $walk = static function (mixed $node) use (&$walk, &$objects): void {
            if (!\is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'object') {
                ++$objects;
                self::assertFalse($node['additionalProperties'] ?? null, 'object schema without additionalProperties: false: '.json_encode($node, \JSON_THROW_ON_ERROR));
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($this->schema);
        self::assertSame(4, $objects, 'document, match, rule, variant');
    }

    public function testTheSharedExampleIsAcceptedAndShownInTheHowTo(): void
    {
        $example = (string) file_get_contents(self::root().'/tests/Fixture/rules-example.json');

        $result = (new RulesDocumentParser())->parse(json_decode($example, false, 512, \JSON_THROW_ON_ERROR));

        self::assertNotNull($result->document);
        self::assertStringContainsString(trim($example), (string) file_get_contents(self::root().'/docs/how-to/local-development.md'), 'the how-to shows the same example document');
    }

    /** `/^…$/D` → `^…$` */
    private static function ecma(string $phpPattern): string
    {
        return substr($phpPattern, 1, (int) strrpos($phpPattern, '/') - 1);
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 4);
    }
}
