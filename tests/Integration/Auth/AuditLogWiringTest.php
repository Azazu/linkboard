<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Tests\Support\Json;
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
        $config = Json::asMap(Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/monolog.yaml'), 'monolog.yaml');

        self::assertContains('audit', Json::listAt($config, 'monolog', 'channels'));

        $handlers = Json::mapAt($config, 'when@prod', 'monolog', 'handlers');
        $audit = Json::map($handlers, 'audit');
        self::assertSame('stream', $audit['type']);
        self::assertSame('php://stderr', $audit['path']);
        self::assertSame('info', $audit['level']);
        self::assertSame(['audit'], $audit['channels']);
        self::assertSame('monolog.formatter.json', $audit['formatter']);

        // the fingers_crossed main handler must not swallow the channel
        $main = Json::map($handlers, 'main');
        self::assertSame('fingers_crossed', $main['type']);
        self::assertContains('!audit', Json::items($main, 'channels'));
    }
}
