<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Reads a decoded JSON body as the type the test expects, asserting as it goes.
 *
 * `json_decode(…, true)` returns `mixed`, so `json_decode($body)['token']` is
 * an offset access on an unknown — which PHPStan level 9 objects to, and the
 * objection is the useful kind: when the field is missing the test carried on
 * with `null` and failed somewhere less informative, or passed. Each reader
 * here fails as an assertion naming the field instead (change
 * harden-gate-floor, design decision 3).
 *
 * What this does NOT do: make a test check more than it checked. Converting a
 * file is a type-level edit; a test that asserted nothing useful still does.
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * A response body that is a JSON object.
     *
     * @return array<string, mixed>
     */
    public static function decode(string|false $body): array
    {
        Assert::assertIsString($body, 'the response has a body');
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded, 'the body is a JSON object');

        $object = [];
        foreach ($decoded as $key => $value) {
            Assert::assertIsString($key, 'the body is a JSON object, not a list');
            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * A response body that is a JSON array.
     *
     * @return list<mixed>
     */
    public static function decodeList(string|false $body): array
    {
        Assert::assertIsString($body, 'the response has a body');
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded, 'the body is a JSON array');
        Assert::assertTrue(array_is_list($decoded), 'the body is a JSON array, not an object');

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function string(array $data, string|int $key): string
    {
        $value = self::at($data, $key);
        Assert::assertIsString($value, self::describe($key).' is a string');

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function int(array $data, string|int $key): int
    {
        $value = self::at($data, $key);
        Assert::assertIsInt($value, self::describe($key).' is an integer');

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function float(array $data, string|int $key): float
    {
        $value = self::at($data, $key);
        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }

        Assert::fail(self::describe($key).' is a number, got '.get_debug_type($value));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function bool(array $data, string|int $key): bool
    {
        $value = self::at($data, $key);
        Assert::assertIsBool($value, self::describe($key).' is a boolean');

        return $value;
    }

    /**
     * A field that is present and either a string or `null` — the two answers
     * an optional text field gives.
     *
     * @param array<array-key, mixed> $data
     */
    public static function nullableString(array $data, string|int $key): ?string
    {
        $value = self::at($data, $key);
        if (null === $value || \is_string($value)) {
            return $value;
        }

        Assert::fail(self::describe($key).' is a string or null, got '.get_debug_type($value));
    }

    /**
     * A nested object.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function map(array $data, string|int $key): array
    {
        $value = self::at($data, $key);
        Assert::assertIsArray($value, self::describe($key).' is an object');

        $object = [];
        foreach ($value as $name => $member) {
            Assert::assertIsString($name, self::describe($key).' is an object, not a list');
            $object[$name] = $member;
        }

        return $object;
    }

    /**
     * A nested array.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<mixed>
     */
    public static function items(array $data, string|int $key): array
    {
        $value = self::at($data, $key);
        Assert::assertIsArray($value, self::describe($key).' is an array');
        Assert::assertTrue(array_is_list($value), self::describe($key).' is an array, not an object');

        return $value;
    }

    /**
     * A nested array whose members are objects — a collection's `items`, a
     * problem document's `violations`.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function objects(array $data, string|int $key): array
    {
        $rows = [];
        foreach (self::items($data, $key) as $index => $member) {
            Assert::assertIsArray($member, self::describe($key)."[$index] is an object");
            $row = [];
            foreach ($member as $name => $value) {
                Assert::assertIsString($name, self::describe($key)."[$index] is an object, not a list");
                $row[$name] = $value;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function at(array $data, string|int $key): mixed
    {
        Assert::assertArrayHasKey($key, $data, self::describe($key).' is present');

        return $data[$key];
    }

    private static function describe(string|int $key): string
    {
        return \sprintf('"%s"', $key);
    }
}
