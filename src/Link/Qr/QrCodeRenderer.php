<?php

declare(strict_types=1);

namespace App\Link\Qr;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Renders a URL as a 512 px QR code (design decision 3): SVG or PNG, error
 * correction Medium. The library's `size` is the code area and `margin` is
 * added on every side, so the area is SIZE − 2 × MARGIN and the image is
 * exactly SIZE × SIZE; RoundBlockSizeMode::Margin puts the block-size
 * rounding into the margin instead of the outer size. Pure function of its
 * arguments — the same URL and format give byte-identical output (the SVG
 * snapshot test relies on it).
 */
final class QrCodeRenderer
{
    public const int SIZE = 512;
    public const int MARGIN = 16;

    public function render(string $url, QrFormat $format): string
    {
        $builder = new Builder(
            writer: match ($format) {
                QrFormat::Svg => new SvgWriter(),
                QrFormat::Png => new PngWriter(),
            },
            data: $url,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: self::SIZE - 2 * self::MARGIN,
            margin: self::MARGIN,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return $builder->build()->getString();
    }
}
