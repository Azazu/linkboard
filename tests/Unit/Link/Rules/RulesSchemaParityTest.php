<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link\Rules;

use App\Link\Rules\RulesDocument;
use App\Link\Rules\RulesDocumentParser;
use App\Tests\Support\Json;
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
    /** @var array<array-key, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $this->schema = Json::decode(file_get_contents(self::root().'/docs/reference/rules-schema.json'));
    }

    public function testTopLevelShape(): void
    {
        $s = $this->schema;
        self::assertSame('object', $s['type']);
        self::assertFalse($s['additionalProperties']);
        self::assertSame(['version'], $s['required']);
        self::assertSame([['required' => ['rules']], ['required' => ['variants']]], $s['anyOf']);
        self::assertSame(RulesDocument::VERSION, Json::mapAt($s, 'properties', 'version')['const']);
        self::assertSame(['type' => 'array', 'minItems' => RulesDocument::MIN_RULES, 'maxItems' => RulesDocument::MAX_RULES, 'items' => ['$ref' => '#/$defs/rule']], Json::mapAt($s, 'properties', 'rules'));
        self::assertSame(['type' => 'array', 'minItems' => RulesDocument::MIN_VARIANTS, 'maxItems' => RulesDocument::MAX_VARIANTS, 'items' => ['$ref' => '#/$defs/variant']], Json::mapAt($s, 'properties', 'variants'));
        self::assertSame(['version', 'rules', 'variants'], array_keys(Json::map($s, 'properties')));
    }

    public function testValueListsMatchTheVocabulariesAndLimits(): void
    {
        $defs = Json::map($this->schema, '$defs');
        foreach (['deviceValues', 'osValues', 'countryValues', 'languageValues'] as $name) {
            $values = Json::map($defs, $name);
            self::assertSame('array', $values['type'], $name);
            self::assertSame(RulesDocument::MIN_VALUES, $values['minItems'], $name);
            self::assertSame(RulesDocument::MAX_VALUES, $values['maxItems'], $name);
            self::assertTrue($values['uniqueItems'], $name);
            self::assertSame('string', Json::map($values, 'items')['type'], $name);
        }
        self::assertSame(RulesDocument::DEVICES, Json::mapAt($defs, 'deviceValues', 'items')['enum']);
        self::assertSame(RulesDocument::OSES, Json::mapAt($defs, 'osValues', 'items')['enum']);
        self::assertSame(self::ecma(RulesDocument::COUNTRY_PATTERN), Json::mapAt($defs, 'countryValues', 'items')['pattern']);
        self::assertSame(self::ecma(RulesDocument::LANGUAGE_PATTERN), Json::mapAt($defs, 'languageValues', 'items')['pattern']);
        self::assertSame(RulesDocument::MATCH_KEYS, array_keys(Json::mapAt($defs, 'match', 'properties')));
        self::assertCount(3, Json::listAt($defs, 'match', 'oneOf'), 'exactly one dimension: device (device and/or os), country, language');
    }

    public function testRuleAndVariantShapes(): void
    {
        $defs = Json::map($this->schema, '$defs');
        self::assertSame(['match', 'target'], Json::map($defs, 'rule')['required']);
        self::assertSame(['name', 'weight', 'target'], Json::map($defs, 'variant')['required']);
        self::assertSame(['type' => 'string', 'pattern' => self::ecma(RulesDocument::NAME_PATTERN)], Json::mapAt($defs, 'variant', 'properties', 'name'));
        self::assertSame(['type' => 'integer', 'minimum' => RulesDocument::MIN_WEIGHT], Json::mapAt($defs, 'variant', 'properties', 'weight'));
        $target = Json::map($defs, 'target');
        self::assertSame('string', $target['type']);
        self::assertSame(RulesDocument::TARGET_MAX_LENGTH, $target['maxLength']);
        self::assertStringContainsString((string) RulesDocument::WEIGHT_SUM, Json::string($this->schema, 'description'));
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
