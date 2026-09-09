<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link;

use App\Link\Validator\TargetUrlPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec links, "Target URL policy": one case per rejected class and per accepted form.
 */
#[CoversClass(TargetUrlPolicy::class)]
final class TargetUrlPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        yield 'apple store' => ['https://apps.apple.com/app/id123'];
        yield 'play store' => ['https://play.google.com/store/apps/details?id=com.example'];
        yield 'plain http' => ['http://example.com'];
        yield 'port, path, query, fragment' => ['https://example.com:8443/a/b?c=1&d=2#frag'];
        yield 'public ipv4 literal' => ['http://93.184.216.34/'];
        yield 'public ipv6 literal' => ['http://[2001:db8::1]/'];
        yield 'uppercase scheme' => ['HTTPS://Example.COM/'];
        yield 'exactly 2048 characters' => ['https://example.com/'.str_repeat('a', 2048 - \strlen('https://example.com/'))];
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'market scheme' => ['market://details?id=x'];
        yield 'itms scheme' => ['itms-apps://apps.apple.com/app/id1'];
        yield 'ftp' => ['ftp://example.com/f'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'data' => ['data:text/html,hi'];
        yield 'relative' => ['/just/a/path'];
        yield 'no host' => ['https:///path'];
        yield 'not a url' => ['not a url'];
        yield 'localhost' => ['http://localhost/'];
        yield 'localhost subdomain' => ['http://app.localhost/'];
        yield 'ipv4 loopback' => ['http://127.0.0.1/'];
        yield 'ipv4 loopback other' => ['http://127.5.6.7/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'private 10/8' => ['http://10.0.0.5/'];
        yield 'private 172.16/12' => ['http://172.16.9.1/'];
        yield 'private 172.31 (top of the /12)' => ['http://172.31.255.255/'];
        yield 'private 192.168/16' => ['http://192.168.1.1/'];
        yield 'link-local metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'ipv6 link-local' => ['http://[fe80::1]/'];
        yield 'ipv6 unique local' => ['http://[fd00::1]/'];
        yield 'ipv6 unique local fc' => ['http://[fc00::abcd]/'];
        yield 'ipv4-mapped ipv6 loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'ipv4-mapped ipv6 private' => ['http://[::ffff:10.0.0.1]/'];
        yield 'this network' => ['http://0.0.0.0/'];
        yield 'whitespace inside' => ['https://exa mple.com/'];
        yield '2049 characters' => ['https://example.com/'.str_repeat('a', 2049 - \strlen('https://example.com/'))];
    }

    #[DataProvider('accepted')]
    public function testAccepted(string $url): void
    {
        self::assertTrue(TargetUrlPolicy::isAllowed($url), $url);
    }

    #[DataProvider('rejected')]
    public function testRejected(string $url): void
    {
        self::assertFalse(TargetUrlPolicy::isAllowed($url), $url);
    }

    public function testBoundariesOfThe17216Range(): void
    {
        self::assertFalse(TargetUrlPolicy::isAllowed('http://172.16.0.0/'));
        self::assertTrue(TargetUrlPolicy::isAllowed('http://172.15.255.255/'));
        self::assertTrue(TargetUrlPolicy::isAllowed('http://172.32.0.0/'));
    }
}
