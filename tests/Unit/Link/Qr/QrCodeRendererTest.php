<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link\Qr;

use App\Link\Qr\QrCodeRenderer;
use App\Link\Qr\QrFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Spec qr-codes "QR code of the short URL" at the renderer level: the SVG
 * snapshot (the brief's §7 exit criterion), the PNG dimensions, determinism.
 * The fixture was rendered once by this renderer; a library upgrade that
 * changes the markup fails the snapshot on purpose — regenerate and review.
 */
#[CoversClass(QrCodeRenderer::class)]
#[CoversClass(QrFormat::class)]
final class QrCodeRendererTest extends TestCase
{
    private const string URL = 'https://example.test/spring-sale';
    private const string FIXTURE = __DIR__.'/../../../Fixture/qr/spring-sale.svg';

    public function testSvgMatchesTheSnapshotAndDeclares512Pixels(): void
    {
        $svg = new QrCodeRenderer()->render(self::URL, QrFormat::Svg);

        self::assertStringEqualsFile(self::FIXTURE, $svg);
        $root = simplexml_load_string($svg);
        self::assertNotFalse($root);
        self::assertSame('svg', $root->getName());
        self::assertSame(['512px', '512px', '0 0 512 512'], [(string) $root['width'], (string) $root['height'], (string) $root['viewBox']]);
    }

    public function testPngIs512By512(): void
    {
        $png = new QrCodeRenderer()->render(self::URL, QrFormat::Png);

        self::assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8), 'PNG signature');
        $info = getimagesizefromstring($png);
        self::assertNotFalse($info);
        self::assertSame([512, 512, \IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }

    public function testRenderingIsDeterministicAndDependsOnTheUrl(): void
    {
        $renderer = new QrCodeRenderer();

        self::assertSame($renderer->render(self::URL, QrFormat::Svg), $renderer->render(self::URL, QrFormat::Svg));
        self::assertNotSame($renderer->render(self::URL, QrFormat::Svg), $renderer->render('https://example.test/other', QrFormat::Svg));
    }

    public function testFormatFromTheQueryParameter(): void
    {
        self::assertSame(QrFormat::Svg, QrFormat::fromQuery(null));
        self::assertSame(QrFormat::Svg, QrFormat::fromQuery('svg'));
        self::assertSame(QrFormat::Png, QrFormat::fromQuery('png'));
        self::assertSame(['image/svg+xml', 'svg'], [QrFormat::Svg->mimeType(), QrFormat::Svg->extension()]);
        self::assertSame(['image/png', 'png'], [QrFormat::Png->mimeType(), QrFormat::Png->extension()]);
    }
}
