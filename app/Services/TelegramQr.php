<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Draws the Telegram start link as an SVG QR code for printed invoices, so a customer links their
 * chat by scanning it.
 */
class TelegramQr
{
    /** The QR as inline SVG markup (no XML declaration), ready to place in HTML. */
    public static function svg(string $url, int $size = 110): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd)))->writeString($url);

        return substr($svg, (int) strpos($svg, '<svg'));
    }

    /** The QR as an image data URI, for Dompdf PDFs. */
    public static function dataUri(string $url, int $size = 110): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($url, $size));
    }
}
