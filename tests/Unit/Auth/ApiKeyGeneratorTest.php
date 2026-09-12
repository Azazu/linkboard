<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\ApiKey\ApiKeyGenerator;
use App\Auth\ApiKey\GeneratedKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** FR-KEY-1: key shape, entropy (distinct keys), the stored hash and prefix. */
#[CoversClass(ApiKeyGenerator::class)]
#[CoversClass(GeneratedKey::class)]
final class ApiKeyGeneratorTest extends TestCase
{
    public function testShapeHashPrefixAndDistinctness(): void
    {
        $generator = new ApiKeyGenerator();
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $key = $generator->generate();
            self::assertMatchesRegularExpression(ApiKeyGenerator::PATTERN, $key->plaintext);
            self::assertSame(hash('sha256', $key->plaintext), $key->hash);
            self::assertSame(substr($key->plaintext, 0, 8), $key->prefix);
            self::assertStringStartsWith('lb_', $key->prefix);
            $seen[$key->plaintext] = true;
        }
        self::assertCount(200, $seen, 'no two generated keys coincide');
    }

    public function testHashIsTheStoredForm(): void
    {
        self::assertSame(hash('sha256', 'lb_x'), ApiKeyGenerator::hash('lb_x'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ApiKeyGenerator::hash('anything'));
    }
}
