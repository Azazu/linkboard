<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Click\Counter\RedisClickCounter;
use App\Click\RecordOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * Spec redirect "Click limit is exact under concurrency" at the counter seam
 * (design decision 2) against the real Redis of REDIS_URL, keys prefixed
 * `test:` and deleted afterwards. The race case spawns ten processes
 * (tests/Fixture/count-click.php) on an absent key.
 */
#[CoversClass(RedisClickCounter::class)]
final class RedisClickCounterTest extends TestCase
{
    private string $redisUrl;
    private \Redis $redis;
    private RedisClickCounter $counter;
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        $this->redisUrl = (string) ($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? '');
        self::assertNotSame('', $this->redisUrl, 'REDIS_URL is required');
        $redis = RedisAdapter::createConnection($this->redisUrl);
        self::assertInstanceOf(\Redis::class, $redis);
        $this->redis = $redis;
        $this->counter = new RedisClickCounter($this->redisUrl, 'test:');
    }

    protected function tearDown(): void
    {
        foreach ($this->keys as $key) {
            $this->redis->del($key);
        }
    }

    public function testAbsentKeyIsSeededAndTheLimitIsExact(): void
    {
        $link = $this->link();

        self::assertSame([RecordOutcome::Allowed, RecordOutcome::Allowed, RecordOutcome::Allowed, RecordOutcome::Exhausted, RecordOutcome::Exhausted], array_map(fn (): RecordOutcome => $this->counter->increment($link, 0, 3), range(1, 5)));
        self::assertSame('3', $this->redis->get($this->counter->key($link)));
    }

    public function testSeedingFromThePersistedCount(): void
    {
        $link = $this->link();

        self::assertSame(RecordOutcome::Allowed, $this->counter->increment($link, 2, 3));
        self::assertSame(RecordOutcome::Exhausted, $this->counter->increment($link, 2, 3));
        self::assertSame('3', $this->redis->get($this->counter->key($link)));
    }

    public function testALimitChangeAppliesToTheNextCall(): void
    {
        $link = $this->link();
        $this->redis->set($this->counter->key($link), '3');

        self::assertSame(RecordOutcome::Exhausted, $this->counter->increment($link, 3, 3));
        self::assertSame(RecordOutcome::Allowed, $this->counter->increment($link, 3, 5));
        self::assertSame('4', $this->redis->get($this->counter->key($link)));
    }

    public function testTheKeyIsLiftedToThePersistedCountAndNeverLowered(): void
    {
        $link = $this->link();
        $this->redis->set($this->counter->key($link), '3');

        self::assertSame(RecordOutcome::Allowed, $this->counter->increment($link, 7, 10), 'a limit re-enabled after an unlimited period starts from the persisted count');
        self::assertSame('8', $this->redis->get($this->counter->key($link)));

        self::assertSame(RecordOutcome::Allowed, $this->counter->increment($link, 3, 10), 'a lower seed does not lower the key');
        self::assertSame('9', $this->redis->get($this->counter->key($link)));
    }

    public function testForgetDeletesTheKeyAndTheNextCallReseeds(): void
    {
        $link = $this->link();
        $this->counter->increment($link, 0, 3);
        self::assertSame('1', $this->redis->get($this->counter->key($link)));

        $this->counter->forget($link);

        self::assertFalse($this->redis->get($this->counter->key($link)));
        self::assertSame(RecordOutcome::Allowed, $this->counter->increment($link, 2, 3));
        self::assertSame('3', $this->redis->get($this->counter->key($link)));
    }

    public function testTenProcessesOnAnAbsentKeyLetExactlyMaxThrough(): void
    {
        $link = $this->link();
        $script = \dirname(__DIR__, 3).'/tests/Fixture/count-click.php';
        $processes = [];
        for ($i = 0; $i < 10; ++$i) {
            $process = new Process(['php', $script, $link->toRfc4122()], \dirname(__DIR__, 3));
            $process->start();
            $processes[] = $process;
        }
        $allowed = 0;
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $allowed += substr_count($process->getOutput(), 'A');
        }

        self::assertSame(3, $allowed, 'exactly max_clicks increments may win');
        self::assertSame('3', $this->redis->get($this->counter->key($link)));
    }

    public function testAFailingClientIsARuntimeException(): void
    {
        $failing = new RedisClickCounter($this->redisUrl, 'test:', static function (): \Redis {
            throw new \RedisException('Connection refused');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Click counter unavailable');

        $failing->increment($this->link(), 0, 3);
    }

    public function testAnErrorReplyIsARuntimeException(): void
    {
        $client = $this->createStub(\Redis::class);
        $client->method('eval')->willThrowException(new \RedisException('ERR Error running script'));
        $failing = new RedisClickCounter($this->redisUrl, 'test:', static fn (): \Redis => $client);
        $this->expectException(\RuntimeException::class);

        $failing->increment($this->link(), 0, 3);
    }

    private function link(): Uuid
    {
        $id = Uuid::v7();
        $this->keys[] = $this->counter->key($id);

        return $id;
    }
}
