<?php

declare(strict_types=1);

namespace App\Click;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * FR-CLK-2 / NFR-SEC-6: visitor_hash = hex SHA-256 over salt, client IP and
 * user agent, NUL-separated so the concatenation is unambiguous. The salt
 * comes from VISITOR_HASH_SALT; rotating it breaks unique-visitor continuity.
 */
final readonly class VisitorHasher
{
    public function __construct(
        #[Autowire(env: 'VISITOR_HASH_SALT')]
        private string $salt,
    ) {
        if ('' === $salt) {
            throw new \InvalidArgumentException('VISITOR_HASH_SALT must not be empty.');
        }
    }

    public function hash(Visit $visit): string
    {
        return hash('sha256', $this->salt."\0".$visit->clientIp."\0".$visit->userAgent);
    }
}
