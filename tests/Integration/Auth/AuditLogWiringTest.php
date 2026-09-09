<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Spec user-administration, "Admin actions are audited": in production the
 * audit channel must reach a handler that writes successful actions — the
 * main handler is fingers_crossed and flushes only when an error occurs.
 * Asserted on the configuration itself: a prod kernel cannot be booted in
 * the test process without leaking its environment into later tests.
 */
#[CoversNothing]
final class AuditLogWiringTest extends TestCase
{
    public function testProductionAuditChannelHasAnAlwaysOnStreamHandler(): void
    {
        /** @var array<string, mixed> $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/monolog.yaml');

        self::assertContains('audit', $config['monolog']['channels']);

        $prod = $config['when@prod']['monolog']['handlers'];
        self::assertSame('stream', $prod['audit']['type']);
        self::assertSame('php://stderr', $prod['audit']['path']);
        self::assertSame('info', $prod['audit']['level']);
        self::assertSame(['audit'], $prod['audit']['channels']);
        self::assertSame('monolog.formatter.json', $prod['audit']['formatter']);

        // the fingers_crossed main handler must not swallow the channel
        self::assertSame('fingers_crossed', $prod['main']['type']);
        self::assertContains('!audit', $prod['main']['channels']);
    }
}
