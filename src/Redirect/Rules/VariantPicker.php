<?php

declare(strict_types=1);

namespace App\Redirect\Rules;

use App\Link\Rules\Variant;
use Symfony\Component\Uid\Uuid;

/**
 * FR-RUL-5: variant = pick(weights, crc32(link_id ‖ client_ip ‖ user_agent) mod 100)
 * over the cumulative weights in document order — deterministic per visitor
 * without cookies or state. NUL separators make the concatenation unambiguous
 * (design decision 10). CRC32 is a distribution function here, not a security
 * primitive; the salted SHA-256 visitor_hash remains the identity at rest.
 */
final class VariantPicker
{
    public const int BUCKETS = 100;

    /** The visitor's bucket in [0, 100). */
    public static function bucket(Uuid $linkId, string $clientIp, string $userAgent): int
    {
        return crc32($linkId->toRfc4122()."\0".$clientIp."\0".$userAgent) % self::BUCKETS;
    }

    /**
     * @param non-empty-list<Variant> $variants weights summing to 100 (validated on write)
     */
    public static function pick(array $variants, Uuid $linkId, string $clientIp, string $userAgent): Variant
    {
        return self::forBucket($variants, self::bucket($linkId, $clientIp, $userAgent));
    }

    /**
     * @param non-empty-list<Variant> $variants
     */
    public static function forBucket(array $variants, int $bucket): Variant
    {
        $cumulative = 0;
        foreach ($variants as $variant) {
            $cumulative += $variant->weight;
            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        return $variants[array_key_last($variants)];
    }

    private function __construct()
    {
    }
}
