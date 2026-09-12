<?php

declare(strict_types=1);

namespace App\Link\Qr;

/**
 * The two image formats of a link's QR code (spec qr-codes "QR code of the
 * short URL"). Selected by the `format` query parameter; SVG when absent.
 */
enum QrFormat: string
{
    case Svg = 'svg';
    case Png = 'png';

    /**
     * The declared `Choice` constraint has already rejected anything but
     * `svg`/`png`; null (parameter absent) is the SVG default.
     */
    public static function fromQuery(?string $value): self
    {
        return null === $value ? self::Svg : self::from($value);
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Svg => 'image/svg+xml',
            self::Png => 'image/png',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
