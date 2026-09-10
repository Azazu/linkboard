<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect\Language;

use App\Redirect\Language\AcceptLanguage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Spec routing-rules "Language resolution" (design decision 9); the header is already well-formed here. */
#[CoversClass(AcceptLanguage::class)]
final class AcceptLanguageTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function headers(): iterable
    {
        yield 'quality order' => ['uk-UA;q=0.8, ru;q=0.9', 'ru'];
        yield 'region stripped' => ['en-US,en;q=0.9', 'en'];
        yield 'quality beats position' => ['de-CH;q=0.7, fr-CH;q=0.9', 'fr'];
        yield 'script and region stripped' => ['zh-Hant-TW', 'zh'];
        yield 'upper-case lowered' => ['DE', 'de'];
        yield 'wildcard' => ['*', null];
        yield 'one letter' => ['x', null];
        yield 'three-letter primary subtag' => ['ast', null];
        yield 'absent' => [null, null];
        yield 'blank' => ['  ', null];
    }

    #[DataProvider('headers')]
    public function testPrimary(?string $header, ?string $expected): void
    {
        self::assertSame($expected, AcceptLanguage::primary($header));
    }
}
