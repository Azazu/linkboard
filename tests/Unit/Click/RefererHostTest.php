<?php

declare(strict_types=1);

namespace App\Tests\Unit\Click;

use App\Click\RefererHost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Spec click-logging, "Click record contents" (referer_host) and "Untrusted header bounds". */
#[CoversClass(RefererHost::class)]
final class RefererHostTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function cases(): iterable
    {
        yield 'lower-cased host' => ['https://News.Example.org/story?id=1', 'news.example.org'];
        yield 'host with port' => ['http://blog.example.com:8080/x', 'blog.example.com'];
        yield 'absent' => [null, null];
        yield 'empty' => ['', null];
        yield 'garbage' => ['not a url', null];
        yield 'scheme-relative garbage' => ['://x', null];
        yield 'own public host' => ['http://localhost:8082/dashboard', null];
        yield 'own public host, other case' => ['http://LOCALHOST:8082/', null];
        yield '300-character host' => ['https://'.str_repeat('a', 300).'/', null];
        yield '255-byte host kept' => ['https://'.str_repeat('a', 255).'/', str_repeat('a', 255)];
        yield 'invalid UTF-8' => ["https://ex\xffample.com/", null];
        yield 'control character' => ["https://exa\x01mple.com/", null];
    }

    #[DataProvider('cases')]
    public function testHost(?string $referer, ?string $expected): void
    {
        self::assertSame($expected, (new RefererHost('http://localhost:8082'))->of($referer));
    }
}
