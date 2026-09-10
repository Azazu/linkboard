<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec routing-rules "Rules document shape" over HTTP; spec links "Link with
 * rules" and "Rules are replaced whole, cleared with null, kept when absent".
 */
#[CoversNothing]
final class LinkRulesTest extends LinkApiTestCase
{
    public function testRulesRoundTripAndPlainLinksHaveNull(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');
        $example = json_decode(self::example(), true, 512, \JSON_THROW_ON_ERROR);

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => 'https://example.com/', 'rules' => $example]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->decode($client);
        self::assertEquals($example, $created['rules'], 'JSON-value-equal to the posted document');

        $this->api($client, $token, 'GET', '/api/v1/links/'.$created['id']);
        self::assertEquals($example, $this->decode($client)['rules']);
        $this->api($client, $token, 'GET', '/api/v1/links');
        self::assertEquals($example, $this->decode($client)['items'][0]['rules']);

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => 'https://example.com/plain']);
        self::assertResponseStatusCodeSame(201);
        self::assertArrayHasKey('rules', $this->decode($client));
        self::assertNull($this->decode($client)['rules']);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function invalidDocuments(): iterable
    {
        $rule = static fn (string $match, string $target = '"https://example.com/"'): string => '{"match":'.$match.',"target":'.$target.'}';
        $doc = static fn (string $rules): string => '{"version":1,"rules":['.$rules.']}';
        yield '21 rules' => [$doc(implode(',', array_fill(0, 21, $rule('{"device":["desktop"]}')))), ['rules[rules]']];
        yield 'device and country' => [$doc($rule('{"device":["desktop"],"country":["DE"]}')), ['rules[rules][0][match]']];
        yield 'empty match' => [$doc($rule('{}')), ['rules[rules][0][match]']];
        yield 'empty device list' => [$doc($rule('{"device":[]}')), ['rules[rules][0][match][device]']];
        yield 'unknown device' => [$doc($rule('{"device":["phone"]}')), ['rules[rules][0][match][device][0]']];
        yield 'lower-case country' => [$doc($rule('{"country":["de"]}')), ['rules[rules][0][match][country][0]']];
        yield 'language with region' => [$doc($rule('{"language":["en-US"]}')), ['rules[rules][0][match][language][0]']];
        yield 'duplicate country' => [$doc($rule('{"country":["DE","DE"]}')), ['rules[rules][0][match][country][1]']];
        yield 'version 2' => ['{"version":2,"rules":['.$rule('{"device":["desktop"]}').']}', ['rules[version]']];
        yield 'extra key' => ['{"version":1,"note":"x","rules":['.$rule('{"device":["desktop"]}').']}', ['rules[note]']];
        yield 'version only' => ['{"version":1}', ['rules']];
        yield 'rules is a string' => ['"x"', ['rules']];
        yield 'rules is a list' => ['[]', ['rules']];
        yield 'rules is an empty object' => ['{}', ['rules']];
        yield 'rules as numeric-keyed object' => ['{"version":1,"rules":{"0":'.$rule('{"country":["DE"]}').'}}', ['rules[rules]']];
        yield 'device as object' => [$doc($rule('{"device":{"0":"smartphone"}}')), ['rules[rules][0][match][device]']];
        yield 'os as object' => [$doc($rule('{"os":{"0":"iOS"}}')), ['rules[rules][0][match][os]']];
        yield 'country as object' => [$doc($rule('{"country":{"0":"DE"}}')), ['rules[rules][0][match][country]']];
        yield 'language as object' => [$doc($rule('{"language":{"0":"de"}}')), ['rules[rules][0][match][language]']];
        yield 'variants as object' => ['{"version":1,"variants":{"0":{"name":"A","weight":50,"target":"https://example.com/a"},"1":{"name":"B","weight":50,"target":"https://example.com/b"}}}', ['rules[variants]']];
        yield 'rule as array' => [$doc('["match","target"]'), ['rules[rules][0]']];
        yield 'match as array' => [$doc($rule('["device"]')), ['rules[rules][0][match]']];
        yield 'variant as array' => ['{"version":1,"variants":[["A",50,"https://example.com/a"],{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['rules[variants][0]']];
        yield 'one variant' => ['{"version":1,"variants":[{"name":"A","weight":50,"target":"https://example.com/a"}]}', ['rules[variants]']];
        yield 'five variants' => ['{"version":1,"variants":['.implode(',', array_map(static fn (int $i): string => '{"name":"V'.$i.'","weight":20,"target":"https://example.com/'.$i.'"}', range(1, 5))).']}', ['rules[variants]']];
        yield 'duplicate names' => ['{"version":1,"variants":[{"name":"A","weight":50,"target":"https://example.com/a"},{"name":"A","weight":50,"target":"https://example.com/b"}]}', ['rules[variants][1][name]']];
        yield 'weights 60 and 50' => ['{"version":1,"variants":[{"name":"A","weight":60,"target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['rules[variants]']];
        yield 'weight 0' => ['{"version":1,"variants":[{"name":"A","weight":0,"target":"https://example.com/a"},{"name":"B","weight":100,"target":"https://example.com/b"}]}', ['rules[variants][0][weight]']];
        yield 'weight as string' => ['{"version":1,"variants":[{"name":"A","weight":"50","target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['rules[variants][0][weight]']];
        yield 'weight as float' => ['{"version":1,"variants":[{"name":"A","weight":50.0,"target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['rules[variants][0][weight]']];
        yield 'long name' => ['{"version":1,"variants":[{"name":"this-name-is-far-too-long","weight":50,"target":"https://example.com/a"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['rules[variants][0][name]']];
        yield 'rule target on the metadata address' => [$doc($rule('{"device":["desktop"]}', '"http://169.254.169.254/latest/meta-data"')), ['rules[rules][0][target]']];
        yield 'variant target with a custom scheme' => ['{"version":1,"variants":[{"name":"A","weight":50,"target":"market://details?id=x"},{"name":"B","weight":50,"target":"https://example.com/b"}]}', ['rules[variants][0][target]']];
    }

    /** @param list<string> $paths */
    #[DataProvider('invalidDocuments')]
    public function testInvalidDocumentsAre422OnPostAndPatchAndLeaveStorageUnchanged(string $rulesJson, array $paths): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');

        $this->api($client, $token, 'POST', '/api/v1/links', rawBody: '{"targetUrl":"https://example.com/","rules":'.$rulesJson.'}');
        self::assertSame($paths, $this->violationPaths($client));

        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'stored']);
        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), rawBody: '{"rules":'.self::example().'}');
        self::assertResponseStatusCodeSame(200);
        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), rawBody: '{"rules":'.$rulesJson.'}');
        self::assertSame($paths, $this->violationPaths($client));
        $this->api($client, $token, 'GET', '/api/v1/links/'.$link->getId());
        self::assertEquals(json_decode(self::example(), true, 512, \JSON_THROW_ON_ERROR), $this->decode($client)['rules'], 'the rejected patch left the stored document unchanged');
    }

    public function testPatchReplacesWholeKeepsWhenAbsentAndClearsWithNull(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $token = $this->token($client, 'a@example.com');
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'patched']);
        $variantsOnly = ['version' => 1, 'variants' => [['name' => 'A', 'weight' => 50, 'target' => 'https://example.com/a'], ['name' => 'B', 'weight' => 50, 'target' => 'https://example.com/b']]];

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), rawBody: '{"rules":'.self::example().'}');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(4, $this->decode($client)['rules']['rules']);

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['rules' => $variantsOnly]);
        self::assertResponseStatusCodeSame(200);
        self::assertEquals($variantsOnly, $this->decode($client)['rules'], 'replaced whole: the four rules are gone');

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['isActive' => true]);
        self::assertResponseStatusCodeSame(200);
        self::assertEquals($variantsOnly, $this->decode($client)['rules'], 'absent: unchanged');

        $this->api($client, $token, 'PATCH', '/api/v1/links/'.$link->getId(), ['rules' => null]);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->decode($client)['rules'], 'null clears');
        $this->api($client, $token, 'GET', '/api/v1/links/'.$link->getId());
        self::assertNull($this->decode($client)['rules']);
    }

    private static function example(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/tests/Fixture/rules-example.json');
    }
}
