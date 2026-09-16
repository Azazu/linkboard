<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\Json;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The accessor the API tests read bodies with: it returns the typed value, and
 * when the field is missing or the wrong type it fails as an assertion naming
 * the field — instead of handing the test a `null` that fails somewhere less
 * informative, or nowhere.
 */
#[CoversClass(Json::class)]
final class JsonTest extends TestCase
{
    public function testDecodeReturnsAnObjectAndDecodeListAnArray(): void
    {
        self::assertSame(['a' => 1], Json::decode('{"a":1}'));
        self::assertSame([1, 2], Json::decodeList('[1,2]'));
    }

    public function testEachReaderReturnsItsType(): void
    {
        $body = Json::decode('{"token":"abc","count":7,"share":12.5,"whole":3,"ok":true,"note":null,"meta":{"a":1},"tags":["x"],"items":[{"id":"1"}]}');

        self::assertSame('abc', Json::string($body, 'token'));
        self::assertSame(7, Json::int($body, 'count'));
        self::assertSame(12.5, Json::float($body, 'share'));
        self::assertSame(3.0, Json::float($body, 'whole'), 'a JSON integer is a number');
        self::assertTrue(Json::bool($body, 'ok'));
        self::assertNull(Json::nullableString($body, 'note'));
        self::assertSame(['a' => 1], Json::map($body, 'meta'));
        self::assertSame(['x'], Json::items($body, 'tags'));
        self::assertSame([['id' => '1']], Json::objects($body, 'items'));
    }

    public function testThePathReadersNavigateAndTreatAnAbsentStepAsEmpty(): void
    {
        $doc = Json::decode('{"paths":{"/links":{"get":{"responses":{"200":{"headers":["X-Total"],"items":[{"id":"a"}]}}}}}}');

        self::assertSame(['headers' => ['X-Total'], 'items' => [['id' => 'a']]], Json::mapAt($doc, 'paths', '/links', 'get', 'responses', '200'));
        self::assertSame(['X-Total'], Json::listAt($doc, 'paths', '/links', 'get', 'responses', '200', 'headers'));
        self::assertSame([['id' => 'a']], Json::objectsAt($doc, 'paths', '/links', 'get', 'responses', '200', 'items'));

        // an absent step is the `?? []` these documents are navigated with
        self::assertSame([], Json::mapAt($doc, 'paths', '/nope', 'get'));
        self::assertSame([], Json::listAt($doc, 'paths', '/links', 'get', 'responses', '404', 'headers'));
        self::assertSame([], Json::objectsAt($doc, 'components', 'schemas'));

        self::assertTrue(Json::hasAt($doc, 'paths', '/links', 'get'));
        self::assertFalse(Json::hasAt($doc, 'paths', '/links', 'post'));
    }

    public function testAJsonObjectWithNumericMemberNamesIsReadByThoseNames(): void
    {
        // PHP turns a JSON object's numeric member names into integers, so a
        // document's responses are keyed by 204, not '204'
        $doc = Json::decode('{"responses":{"204":{"description":"gone"}}}');

        self::assertSame([204], array_keys(Json::mapAt($doc, 'responses')));
        self::assertSame('gone', Json::string(Json::mapAt($doc, 'responses', '204'), 'description'));
        self::assertTrue(Json::hasAt($doc, 'responses', '204'));
    }

    public function testAPathStepThatIsPresentInTheWrongShapeFailsRatherThanReadingAsEmpty(): void
    {
        $doc = Json::decode('{"responses":{"200":"ok"}}');

        try {
            Json::mapAt($doc, 'responses', '200');
            self::fail('a string was read as an object');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('"responses.200" is an object', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{callable(array<string, mixed>): mixed, string}>
     */
    public static function failing(): iterable
    {
        yield 'a missing field' => [static fn (array $b): mixed => Json::string($b, 'nope'), '"nope" is present'];
        yield 'a string that is a number' => [static fn (array $b): mixed => Json::string($b, 'count'), '"count" is a string'];
        yield 'an int that is a string' => [static fn (array $b): mixed => Json::int($b, 'token'), '"token" is an integer'];
        yield 'a number that is a string' => [static fn (array $b): mixed => Json::float($b, 'token'), '"token" is a number'];
        yield 'a bool that is an int' => [static fn (array $b): mixed => Json::bool($b, 'count'), '"count" is a boolean'];
        yield 'a nullable string that is an int' => [static fn (array $b): mixed => Json::nullableString($b, 'count'), '"count" is a string or null'];
        yield 'an object that is a scalar' => [static fn (array $b): mixed => Json::map($b, 'token'), '"token" is an object'];
        yield 'an array that is an object' => [static fn (array $b): mixed => Json::items($b, 'meta'), '"meta" is an array, not an object'];
        yield 'objects that are scalars' => [static fn (array $b): mixed => Json::objects($b, 'tags'), '"tags"[0] is an object'];
    }

    /**
     * @param callable(array<string, mixed>): mixed $read
     */
    #[DataProvider('failing')]
    public function testAFieldThatIsMissingOrWrongFailsNamingTheField(callable $read, string $expected): void
    {
        $body = Json::decode('{"token":"abc","count":7,"meta":{"a":1},"tags":["x"]}');

        try {
            $read($body);
            self::fail("reading succeeded where it should have failed: $expected");
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testABodyThatIsNotAnObjectFailsRatherThanBeingIndexed(): void
    {
        foreach (['[1,2]' => 'the body is a JSON object', '"just a string"' => 'the body is a JSON object'] as $body => $expected) {
            try {
                Json::decode($body);
                self::fail("decode accepted $body");
            } catch (AssertionFailedError $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    public function testAMissingResponseBodyFails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('the response has a body');

        Json::decode(false);
    }
}
