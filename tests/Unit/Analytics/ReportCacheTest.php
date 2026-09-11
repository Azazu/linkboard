<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Cache\ReportCache;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/** Design decision 7: the gate computes without the cache and logs when the store fails. */
#[CoversClass(ReportCache::class)]
final class ReportCacheTest extends TestCase
{
    public function testAFailingStoreStillYieldsTheComputedReportOnce(): void
    {
        $log = new TestHandler();
        $cache = new ReportCache(self::brokenPool(), new Logger('test', [$log]));
        $calls = 0;

        $report = $cache->remember('summary.abc', ['link-x'], static function () use (&$calls): object {
            ++$calls;

            return new \stdClass();
        });

        self::assertInstanceOf(\stdClass::class, $report);
        self::assertSame(1, $calls);
        self::assertCount(1, $log->getRecords());
        self::assertSame(Logger::WARNING, $log->getRecords()[0]->level->value);
        self::assertSame(\RuntimeException::class, $log->getRecords()[0]->context['exception']);
    }

    public function testAFailingInvalidationIsLoggedWithTheLinkAndNeverThrows(): void
    {
        $log = new TestHandler();
        $cache = new ReportCache(self::brokenPool(), new Logger('test', [$log]));
        $link = Uuid::v7();

        $cache->forgetLink($link);
        $cache->forgetGlobal($link);

        self::assertCount(2, $log->getRecords());
        self::assertSame($link->toRfc4122(), $log->getRecords()[0]->context['link_id']);
        self::assertSame(['link-'.$link->toRfc4122()], $log->getRecords()[0]->context['tags']);
        self::assertSame(['global'], $log->getRecords()[1]->context['tags']);
    }

    public function testARefusedInvalidationIsLoggedToo(): void
    {
        $log = new TestHandler();
        $refusing = new class implements TagAwareCacheInterface {
            /** @param array<string, mixed>|null $metadata */
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw new \LogicException('not exercised here');
            }

            public function delete(string $key): bool
            {
                return false;
            }

            public function invalidateTags(array $tags): bool
            {
                return false; // what the Symfony adapter returns after logging a failure itself
            }
        };
        $link = Uuid::v7();

        (new ReportCache($refusing, new Logger('test', [$log])))->forgetLink($link);

        self::assertCount(1, $log->getRecords());
        self::assertSame($link->toRfc4122(), $log->getRecords()[0]->context['link_id']);
        self::assertNull($log->getRecords()[0]->context['exception']);
    }

    private static function brokenPool(): TagAwareCacheInterface
    {
        return new class implements TagAwareCacheInterface {
            /** @param array<string, mixed>|null $metadata */
            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                throw new \RuntimeException('store down');
            }

            public function delete(string $key): bool
            {
                throw new \RuntimeException('store down');
            }

            public function invalidateTags(array $tags): bool
            {
                throw new \RuntimeException('store down');
            }
        };
    }
}
