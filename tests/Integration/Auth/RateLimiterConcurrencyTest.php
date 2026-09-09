<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The sliding window is read-modify-write on the cache storage; without a
 * shared lock two concurrent requests can both pass at nine consumed tokens.
 * 25 processes race on one key against real Redis: exactly the limit may win.
 */
#[CoversNothing]
final class RateLimiterConcurrencyTest extends KernelTestCase
{
    public function testParallelConsumersNeverExceedTheLimit(): void
    {
        $key = 'race-'.bin2hex(random_bytes(6));
        $script = \dirname(__DIR__, 3).'/tests/Fixture/consume-limiter.php';

        $processes = [];
        for ($i = 0; $i < 25; ++$i) {
            $process = new Process(['php', $script, $key], \dirname(__DIR__, 3));
            $process->start();
            $processes[] = $process;
        }
        $accepted = 0;
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $accepted += substr_count($process->getOutput(), 'A');
        }

        self::assertSame(10, $accepted, 'exactly RATE_LIMIT_AUTH_PER_IP consumers may pass under concurrency');

        self::bootKernel();
        $factory = self::getContainer()->get('limiter.auth_ip_redis');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $factory);
        $factory->create($key)->reset();
    }
}
