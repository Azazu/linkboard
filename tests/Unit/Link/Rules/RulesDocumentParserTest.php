<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link\Rules;

use App\Link\Rules\Dimension;
use App\Link\Rules\ParseResult;
use App\Link\Rules\Rule;
use App\Link\Rules\RulesDocument;
use App\Link\Rules\RulesDocumentParser;
use App\Link\Rules\RuleViolation;
use App\Link\Rules\Variant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec routing-rules "Rules document shape": every scenario's violation path,
 * over the type-preserving tree (json_decode with objects) and, for stored
 * documents, over the canonical associative form (design decision 1).
 */
#[CoversClass(RulesDocumentParser::class)]
#[CoversClass(RulesDocument::class)]
#[CoversClass(Rule::class)]
#[CoversClass(Variant::class)]
#[CoversClass(Dimension::class)]
#[CoversClass(ParseResult::class)]
#[CoversClass(RuleViolation::class)]
final class RulesDocumentParserTest extends TestCase
{
    public function testTheSpecificationExampleParsesAndRoundTrips(): void
    {
        $json = (string) file_get_contents(\dirname(__DIR__, 4).'/tests/Fixture/rules-example.json');

        $result = (new RulesDocumentParser())->parse(json_decode($json, false, 512, \JSON_THROW_ON_ERROR));

        self::assertSame([], $result->violations);
        $document = $result->document;
        self::assertNotNull($document);
        self::assertSame([Dimension::Device, Dimension::Device, Dimension::Country, Dimension::Language], array_map(static fn (Rule $r): Dimension => $r->dimension, $document->rules));
        self::assertSame(['device' => ['smartphone', 'tablet'], 'os' => ['iOS']], $document->rules[0]->match);
        self::assertSame(['os' => ['Android']], $document->rules[1]->match);
        self::assertSame(['A', 'B'], array_map(static fn (Variant $v): string => $v->name, $document->variants));
        self::assertTrue($document->hasVariants());
        self::assertEquals(json_decode($json, true), $document->toArray(), 'canonical form is JSON-value-equal to the input');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function invalidDocuments(): iterable
    {
        $rule = static fn (string $match, string $target = '"https://example.com/"'): string => '{"match":'.$match.',"target":'.$target.'}';
        $doc = static fn (string $rules): string => '{"version":1,"rules":['.$rules.']}';
        // structural (spec "Structural violations name their path")
        yield '21 rules' => [$doc(implode(',', array_fill(0, 21, $rule('{"device":["desktop"]}')))), ['[rules]']];
        yield 'device and country' => [$doc($rule('{"device":["desktop"],"country":["DE"]}')), ['[rules][0][match]']];
        yield 'empty match' => [$doc($rule('{}')), ['[rules][0][match]']];
        yield 'empty device list' => [$doc($rule('{"device":[]}')), ['[rules][0][match][device]']];
        yield 'unknown device' => [$doc($rule('{"device":["phone"]}')), ['[rules][0][match][device][0]']];
        yield 'lower-case country' => [$doc($rule('{"country":["de"]}')), ['[rules][0][match][country][0]']];
        yield 'language with region' => [$doc($rule('{"language":["en-US"]}')), ['[rules][0][match][language][0]']];
        yield 'duplicate country' => [$doc($rule('{"country":["DE","DE"]}')), ['[rules][0][match][country][1]']];
        yield 'version 2' => ['{"version":2,"rules":['.$rule('{"device":["desktop"]}').']}', ['[version]']];
        yield 'extra top-level key' => ['{"version":1,"note":"x","rules":['.$rule('{"device":["desktop"]}').']}', ['[note]']];
        yield 'version only' => ['{"version":1}', ['']];
        yield 'root string' => ['"x"', ['']];
        yield 'root list' => ['[]', ['']];
        yield 'root empty object' => ['{}', ['']];
        yield 'root number' => ['1', ['']];
        // JSON kinds (spec "JSON objects where arrays are required, arrays where objects are required")
        yield 'rules as numeric-keyed object' => ['{"version":1,"rules":{"0":'.$rule('{"country":["DE"]}').'}}', ['[rules]']];
        yield 'device as object' => [$doc($rule('{"device":{"0":"smartphone"}}')), ['[rules][0][match][device]']];
        yield 'os as object' => [$doc($rule('{"os":{"0":"iOS"}}')), ['[rules][0][match][os]']];
        yield 'country as object' => [$doc($rule('{"country":{"0":"DE"}}')), ['[rules][0][match][country]']];
        yield 'language as object' => [$doc($rule('{"language":{"0":"de"}}')), ['[rules][0][match][language]']];
        yield 'variants as object' => ['{"version":1,"variants":{"0":{"name":"A","weight":50,"target":"https://example.com/a"},"1":{"name":"B","weight":50,"target":"https://example.com/b"}}}', ['[variants]']];
        yield 'rule as array' => [$doc('["match","target"]'), ['[rules][0]']];
        yield 'match as array' => [$doc($rule('["device"]')), ['[rules][0][match]']];
        yield 'variant as array' => ['{"version":1,"variants":[["A",50,"https://example.com/a"],{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['[variants][0]']];
        // variants (spec "Variant violations name their path")
        yield 'one variant' => ['{"version":1,"variants":[{"name":"A","weight":50,"target":"https://example.com/a"}]}', ['[variants]']];
        yield 'five variants' => ['{"version":1,"variants":['.implode(',', array_map(static fn (int $i): string => '{"name":"V'.$i.'","weight":20,"target":"https://example.com/'.$i.'"}', range(1, 5))).']}', ['[variants]']];
        yield 'duplicate names' => ['{"version":1,"variants":[{"name":"A","weight":50,"target":"https://example.com/a"},{"name":"A","weight":50,"target":"https://example.com/b"}]}', ['[variants][1][name]']];
        yield 'weights 60 and 50' => ['{"version":1,"variants":[{"name":"A","weight":60,"target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['[variants]']];
        yield 'weight 0' => ['{"version":1,"variants":[{"name":"A","weight":0,"target":"https://example.com/a"},{"name":"B","weight":100,"target":"https://example.com/b"}]}', ['[variants][0][weight]']];
        yield 'weight as string' => ['{"version":1,"variants":[{"name":"A","weight":"50","target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['[variants][0][weight]']];
        yield 'weight as float' => ['{"version":1,"variants":[{"name":"A","weight":50.0,"target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['[variants][0][weight]']];
        yield 'long name' => ['{"version":1,"variants":[{"name":"this-name-is-far-too-long","weight":50,"target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['[variants][0][name]']];
        // targets (spec "Rule and variant targets obey the target URL policy")
        yield 'rule target on the metadata address' => [$doc($rule('{"device":["desktop"]}', '"http://169.254.169.254/latest/meta-data"')), ['[rules][0][target]']];
        yield 'variant target with a custom scheme' => ['{"version":1,"variants":[{"name":"A","weight":50,"target":"market://details?id=x"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['[variants][0][target]']];
    }

    /** @param list<string> $paths */
    #[DataProvider('invalidDocuments')]
    public function testViolationPaths(string $json, array $paths): void
    {
        $result = (new RulesDocumentParser())->parse(json_decode($json, false, 512, \JSON_THROW_ON_ERROR));

        self::assertNull($result->document);
        $actual = array_values(array_unique(array_map(static fn (RuleViolation $v): string => $v->path, $result->violations)));
        sort($actual);
        sort($paths);
        self::assertSame($paths, $actual);
    }

    public function testStoredCanonicalFormParsesToTheSameModel(): void
    {
        $parser = new RulesDocumentParser();
        $json = (string) file_get_contents(\dirname(__DIR__, 4).'/tests/Fixture/rules-example.json');
        $fromInput = $parser->parse(json_decode($json, false, 512, \JSON_THROW_ON_ERROR))->document;
        self::assertNotNull($fromInput);

        $fromStorage = $parser->parse($fromInput->toArray())->document;

        self::assertNotNull($fromStorage);
        self::assertEquals($fromInput, $fromStorage);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedStoredDocuments(): iterable
    {
        $variants = [['name' => 'A', 'weight' => 50, 'target' => 'https://example.com/a'], ['name' => 'B', 'weight' => 50, 'target' => 'https://example.com/b']];
        yield 'version 2' => [['version' => 2, 'variants' => $variants], '[version]'];
        yield 'rules as string' => [['version' => 1, 'rules' => 'broken'], '[rules]'];
        yield 'two dimensions' => [['version' => 1, 'rules' => [['match' => ['device' => ['desktop'], 'country' => ['DE']], 'target' => 'https://example.com/']]], '[rules][0][match]'];
        yield 'weight as string' => [['version' => 1, 'variants' => [['name' => 'A', 'weight' => '50', 'target' => 'https://example.com/a'], $variants[1]]], '[variants][0][weight]'];
    }

    /** @param array<string, mixed> $stored */
    #[DataProvider('malformedStoredDocuments')]
    public function testDistinguishableMalformedStoredDocumentsAreRejected(array $stored, string $path): void
    {
        $result = (new RulesDocumentParser())->parse($stored);

        self::assertNull($result->document);
        self::assertContains($path, array_map(static fn (RuleViolation $v): string => $v->path, $result->violations));
    }
}
