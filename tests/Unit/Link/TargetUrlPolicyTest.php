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
        yield 'hostname with a trailing dot' => ['http://example.com./'];
        yield 'punycode hostname' => ['http://xn--e1afmkfd.xn--p1ai/'];
        yield 'numeric first label is still a hostname' => ['http://1.example.com/'];
        yield 'public address as a decimal integer' => ['http://1572395042/'];
        yield 'public address in hex' => ['http://0x5db8d822/'];
        yield 'public address with a trailing dot' => ['http://93.184.216.34./'];
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
        yield 'empty string' => [''];
        // alternative IPv4 spellings a browser resolves to a blocked address (WHATWG host parser)
        yield 'loopback shorthand 127.1' => ['http://127.1/'];
        yield 'loopback as a decimal integer' => ['http://2130706433/'];
        yield 'loopback in hex' => ['http://0x7f000001/'];
        yield 'loopback in hex, uppercase' => ['http://0X7F000001/'];
        yield 'loopback in octal' => ['http://0177.0.0.1/'];
        yield 'loopback with a trailing dot' => ['http://127.0.0.1./'];
        yield 'metadata service as a decimal integer' => ['http://2852039166/'];
        yield 'private 10/8 shorthand' => ['http://10.1/'];
        yield 'single number in 0/8' => ['http://1/'];
        yield 'percent-encoded loopback' => ['http://%31%32%37.0.0.1/'];
        // numeric hosts a browser fails to parse at all
        yield 'five numeric labels' => ['http://1.2.3.4.5/'];
        yield 'number above 255' => ['http://256.1.1.1/'];
        yield 'last number too large for the remaining bytes' => ['http://1.2.70000/'];
        yield 'leading zero without octal digits' => ['http://08.0.0.1/'];
        yield 'bare 0x' => ['http://0x/'];
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
