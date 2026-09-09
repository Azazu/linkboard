<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The request-path limiter runs on array storage in tests; this consumes its
 * twin `auth_ip_redis` (same policy, Redis pool in every environment) so the
 * production storage is exercised against the real Redis.
 */
#[CoversNothing]
final class RateLimiterStorageTest extends KernelTestCase
{
    public function testRedisBackedLimiterPersistsTheWindow(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('limiter.auth_ip_redis');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $key = 'test-'.bin2hex(random_bytes(6));

        $first = $factory->create($key)->consume();
        $second = $factory->create($key)->consume();

        self::assertTrue($first->isAccepted());
        self::assertTrue($second->isAccepted());
        self::assertSame($first->getRemainingTokens() - 1, $second->getRemainingTokens(), 'the second consume must see the first one: the state lives in Redis, not in the process');

        // the state is in Redis, not in this process: a fresh kernel sees it too
        self::ensureKernelShutdown();
        self::bootKernel();
        $again = self::getContainer()->get('limiter.auth_ip_redis');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $again);
        $third = $again->create($key)->consume();
        self::assertSame($second->getRemainingTokens() - 1, $third->getRemainingTokens());

        $again->create($key)->reset();
    }
}
