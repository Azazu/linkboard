<?php

declare(strict_types=1);

namespace App\Analytics\Cache;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * The single gate to the report cache (design decision 7): the pool
 * `cache.reports` (tag-aware Redis, TTL 300 s). The cache is an optimisation,
 * never a dependency. The adapter itself fails open — a fetch failure is a
 * miss, a save or invalidation failure is logged at warning with the
 * exception and reported as `false` — so the report is computed either way;
 * this class adds the belt: anything the adapter still throws is caught and
 * logged, and a refused invalidation is logged with the link id, never
 * failing the link write that asked for it.
 */
final readonly class ReportCache
{
    public const string GLOBAL_TAG = 'global';

    public function __construct(
        #[Autowire(service: 'cache.reports')]
        private TagAwareCacheInterface $pool,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @template T of object
     *
     * @param list<string>  $tags
     * @param callable(): T $compute
     *
     * @return T
     */
    public function remember(string $key, array $tags, callable $compute): object
    {
        try {
            return $this->pool->get($key, static function (ItemInterface $item) use ($tags, $compute): object {
                $item->tag($tags);

                return $compute();
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Report cache unavailable; report computed without it', ['exception' => $e::class]);

            return $compute();
        }
    }

    public static function linkTag(Uuid $linkId): string
    {
        return 'link-'.$linkId->toRfc4122();
    }

    /** Drops every cached report of the link (best effort). */
    public function forgetLink(Uuid $linkId): void
    {
        $this->invalidate([self::linkTag($linkId)], $linkId);
    }

    /** Drops every cached global report (best effort). */
    public function forgetGlobal(?Uuid $causeLinkId = null): void
    {
        $this->invalidate([self::GLOBAL_TAG], $causeLinkId);
    }

    /**
     * @param list<string> $tags
     */
    private function invalidate(array $tags, ?Uuid $linkId): void
    {
        try {
            $ok = $this->pool->invalidateTags($tags);
            $exception = null;
        } catch (\Throwable $e) {
            $ok = false;
            $exception = $e::class;
        }
        if (!$ok) {
            $this->logger->warning('Report cache not invalidated', ['link_id' => $linkId?->toRfc4122(), 'tags' => $tags, 'exception' => $exception]);
        }
    }
}
