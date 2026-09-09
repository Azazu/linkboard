<?php

declare(strict_types=1);

namespace App\Tests\Unit\Click;

use App\Click\Visit;
use App\Click\VisitorHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Spec click-logging, "Click record contents" and "Personal data minimisation". */
#[CoversClass(VisitorHasher::class)]
#[CoversClass(Visit::class)]
final class VisitorHasherTest extends TestCase
{
    public function testSameIpAndUserAgentHashTheSame(): void
    {
        $hasher = new VisitorHasher('salt-a');
        $now = new \DateTimeImmutable();

        $first = $hasher->hash(new Visit('203.0.113.7', 'Probe/1.0', null, $now));
        $second = $hasher->hash(new Visit('203.0.113.7', 'Probe/1.0', 'https://example.org/', $now->modify('+1 hour')));

        self::assertSame($first, $second, 'referer and time play no part');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', $first);
    }

    public function testDifferentUserAgentIpOrSaltHashDifferently(): void
    {
        $now = new \DateTimeImmutable();
        $base = (new VisitorHasher('salt-a'))->hash(new Visit('203.0.113.7', 'Probe/1.0', null, $now));

        self::assertNotSame($base, (new VisitorHasher('salt-a'))->hash(new Visit('203.0.113.7', 'Probe/2.0', null, $now)));
        self::assertNotSame($base, (new VisitorHasher('salt-a'))->hash(new Visit('203.0.113.8', 'Probe/1.0', null, $now)));
        self::assertNotSame($base, (new VisitorHasher('salt-b'))->hash(new Visit('203.0.113.7', 'Probe/1.0', null, $now)));
    }

    public function testPartsAreSeparated(): void
    {
        // without separators "ab"+"c" and "a"+"bc" would collide
        $hasher = new VisitorHasher('s');
        $now = new \DateTimeImmutable();
        self::assertNotSame($hasher->hash(new Visit('ab', 'c', null, $now)), $hasher->hash(new Visit('a', 'bc', null, $now)));
    }

    public function testEmptySaltIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VisitorHasher('');
    }

    public function testHeaderBoundsAreAppliedWhenBuildingAVisit(): void
    {
        $visit = Visit::fromHeaders(null, str_repeat('u', 5000), str_repeat('r', 5000), new \DateTimeImmutable(), false);

        self::assertSame('', $visit->clientIp);
        self::assertSame(Visit::USER_AGENT_MAX_BYTES, \strlen($visit->userAgent));
        self::assertSame(Visit::REFERER_MAX_BYTES, \strlen((string) $visit->referer));
    }
}
