<?php

declare(strict_types=1);

namespace App\Click;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * referer_host of a click (FR-CLK-2): the lower-cased host of the Referer
 * header, or null when the header is absent, unparseable, same-origin (the
 * service's own public host) or unfit for the column it is stored in — longer
 * than 255 bytes, not valid UTF-8, or containing a control character. A
 * hostile Referer must never fail the INSERT and so lose a click.
 */
final readonly class RefererHost
{
    public const int MAX_BYTES = 255;

    private string $ownHost;

    public function __construct(
        #[Autowire(env: 'APP_PUBLIC_URL')]
        string $publicUrl,
    ) {
        $this->ownHost = strtolower((string) (parse_url($publicUrl, \PHP_URL_HOST) ?? ''));
    }

    public function of(?string $referer): ?string
    {
        // control characters are checked on the raw header: parse_url would
        // silently rewrite them to "_" and hand back a host that never existed
        if (null === $referer || '' === $referer || 1 === preg_match('/[\x00-\x1f\x7f]/', $referer)) {
            return null;
        }
        $host = parse_url($referer, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return null;
        }
        $host = strtolower($host);
        if (\strlen($host) > self::MAX_BYTES || !mb_check_encoding($host, 'UTF-8')) {
            return null;
        }

        return $host === $this->ownHost ? null : $host;
    }
}
