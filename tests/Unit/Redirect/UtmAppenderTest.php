<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect;

use App\Redirect\UtmAppender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Spec redirect, "Destination with UTM appended" (design decision 6). */
#[CoversClass(UtmAppender::class)]
final class UtmAppenderTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function cases(): iterable
    {
        yield 'query and fragment, existing key replaced, space encoded' => [
            'https://example.com/p?a=1&utm_source=old#top',
            ['utm_source' => 'newsletter', 'utm_campaign' => 'spring sale'],
            'https://example.com/p?a=1&utm_source=newsletter&utm_campaign=spring%20sale#top',
        ];
        yield 'unrelated components survive untouched, every spelling of the key removed' => [
            'https://example.com/p?tag=a&tag=b&a.b=1&x%5By%5D=2&utm%5Fsource=old&utm_source=old2',
            ['utm_source' => 'news'],
            'https://example.com/p?tag=a&tag=b&a.b=1&x%5By%5D=2&utm_source=news',
        ];
        yield 'no query' => ['https://example.com/p', ['utm_medium' => 'email'], 'https://example.com/p?utm_medium=email'];
        yield 'empty query' => ['https://example.com/p?', ['utm_medium' => 'email'], 'https://example.com/p?utm_medium=email'];
        yield 'port kept' => ['https://example.com:8443/p?x=1', ['utm_term' => 'a&b=c'], 'https://example.com:8443/p?x=1&utm_term=a%26b%3Dc'];
        yield 'encoded values kept verbatim' => ['https://example.com/p?q=%E2%9C%93&r=a+b', ['utm_content' => 'ü'], 'https://example.com/p?q=%E2%9C%93&r=a+b&utm_content=%C3%BC'];
        yield 'fixed order of appended keys' => [
            'https://example.com/',
            ['utm_content' => 'c', 'utm_source' => 's', 'utm_medium' => 'm'],
            'https://example.com/?utm_source=s&utm_medium=m&utm_content=c',
        ];
        yield 'utm keys the link does not set are left alone' => ['https://example.com/?utm_term=keep', ['utm_source' => 's'], 'https://example.com/?utm_term=keep&utm_source=s'];
        yield 'key without value' => ['https://example.com/?flag&utm_source=old', ['utm_source' => 's'], 'https://example.com/?flag&utm_source=s'];
        yield 'fragment only' => ['https://example.com/p#frag', ['utm_source' => 's'], 'https://example.com/p?utm_source=s#frag'];
    }

    /** @param array<string, string> $utm */
    #[DataProvider('cases')]
    public function testAppend(string $target, array $utm, string $expected): void
    {
        self::assertSame($expected, UtmAppender::append($target, $utm));
    }

    public function testNoUtmLeavesTheTargetIdentical(): void
    {
        $target = 'https://example.com/p?a=1&a=2&b.c=%20#x';
        self::assertSame($target, UtmAppender::append($target, []));
    }
}
