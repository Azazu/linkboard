<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Db;

use App\Auth\Entity\User;
use App\Shared\Db\OneResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `getOneOrNullResult()` is typed `mixed`, so a repository promising `?User`
 * was promising something the analyser could not see. The narrowing is checked
 * at runtime, in production too, rather than asserted in a docblock.
 */
#[CoversClass(OneResult::class)]
final class OneResultTest extends TestCase
{
    public function testNullPassesThroughAndAMatchingObjectIsReturned(): void
    {
        self::assertNull(OneResult::orNull(null, User::class));

        $user = new User('someone@example.com', 'hash', new \DateTimeImmutable('2026-09-16T00:00:00Z'));
        self::assertSame($user, OneResult::orNull($user, User::class));
    }

    public function testAnythingElseIsRefusedAndNamesBothTypes(): void
    {
        foreach ([['a'], 'a', 7, new \stdClass()] as $value) {
            try {
                OneResult::orNull($value, User::class);
                self::fail('a '.get_debug_type($value).' was accepted as a User');
            } catch (\LogicException $e) {
                self::assertStringContainsString(User::class, $e->getMessage());
                self::assertStringContainsString(get_debug_type($value), $e->getMessage());
            }
        }
    }
}
