<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PngPdfImageTest extends TestCase
{
    public function testRgbPngIsEmbeddedWithPngPredictor(): void
    {
        $compressed = gzcompress("\x00\xff\x00\x00");
        self::assertIsString($compressed);
        $png = $this->png(1, 1, 8, 2, $compressed);
        $src = 'data:image/png;base64,' . base64_encode($png);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:20px;height:20px"></p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString('/Subtype /Image', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $pdf);
        self::assertStringContainsString('/BitsPerComponent 8', $pdf);
        self::assertStringContainsString('/Filter /FlateDecode', $pdf);
        self::assertStringContainsString('/Predictor 15', $pdf);
        self::assertStringContainsString('/Colors 3', $pdf);
        self::assertStringContainsString('/Columns 1', $pdf);
        self::assertStringContainsString('/Im1 Do', $pdf);
    }

    public function testRgbaPngUsesSoftMask(): void
    {
        $compressed = gzcompress("\x00\xff\x00\x00\x80");
        self::assertIsString($compressed);
        $png = $this->png(1, 1, 8, 6, $compressed);
        $src = 'data:image/png;base64,' . base64_encode($png);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:20px;height:20px"></p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString('/ColorSpace /DeviceRGB', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $pdf);
        self::assertStringContainsString('/SMask ', $pdf);
        self::assertSame(2, substr_count($pdf, '/Subtype /Image'));
        self::assertStringContainsString('/Im1 Do', $pdf);
    }

    public function testSixteenBitRgbaPngUsesJsCompatibleEightBitSoftMask(): void
    {
        $compressed = gzcompress("\x00\x12\x34\x56\x78\x9a\xbc\xde\xf0");
        self::assertIsString($compressed);
        $png = $this->png(1, 1, 16, 6, $compressed);
        $src = 'data:image/png;base64,' . base64_encode($png);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:20px;height:20px"></p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString('/ColorSpace /DeviceRGB', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $pdf);
        self::assertStringContainsString('/BitsPerComponent 8', $pdf);
        self::assertStringContainsString('/SMask ', $pdf);
        self::assertSame(2, substr_count($pdf, '/Subtype /Image'));
        self::assertStringContainsString('/Im1 Do', $pdf);
    }

    public function testIndexedPngUsesPaletteAndTransparencySoftMask(): void
    {
        $compressed = gzcompress("\x00\x1b");
        self::assertIsString($compressed);
        $palette = "\xff\x00\x00" . "\x00\xff\x00" . "\x00\x00\xff" . "\xff\xff\xff";
        $transparency = "\xff\x80\x00\xff";
        $png = $this->png(4, 1, 2, 3, $compressed, $palette, $transparency);
        $src = 'data:image/png;base64,' . base64_encode($png);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:40px;height:10px"></p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB 3 <FF000000FF000000FFFFFFFF>]', $pdf);
        self::assertStringContainsString('/BitsPerComponent 2', $pdf);
        self::assertStringContainsString('/Predictor 15', $pdf);
        self::assertStringContainsString('/Colors 1', $pdf);
        self::assertStringContainsString('/SMask ', $pdf);
        self::assertSame(2, substr_count($pdf, '/Subtype /Image'));
        self::assertStringContainsString('/Im1 Do', $pdf);
    }

    public function testGrayscaleTrnsUsesPdfColorKeyMaskWithoutSoftMask(): void
    {
        $compressed = gzcompress("\x00\x7f");
        self::assertIsString($compressed);
        $png = $this->png(1, 1, 8, 0, $compressed, null, pack('n', 127));
        $src = 'data:image/png;base64,' . base64_encode($png);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:20px;height:20px"></p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString('/ColorSpace /DeviceGray', $pdf);
        self::assertStringContainsString('/Mask [127 127]', $pdf);
        self::assertStringNotContainsString('/SMask ', $pdf);
        self::assertSame(1, substr_count($pdf, '/Subtype /Image'));
    }

    public function testRgbTrnsUsesPdfColorKeyMask(): void
    {
        $compressed = gzcompress("\x00\x0a\x14\x1e");
        self::assertIsString($compressed);
        $png = $this->png(1, 1, 8, 2, $compressed, null, pack('nnn', 10, 20, 30));
        $src = 'data:image/png;base64,' . base64_encode($png);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:20px;height:20px"></p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString('/ColorSpace /DeviceRGB', $pdf);
        self::assertStringContainsString('/Mask [10 10 20 20 30 30]', $pdf);
    }

    private function png(
        int $width,
        int $height,
        int $bitDepth,
        int $colorType,
        string $idat,
        ?string $palette = null,
        ?string $transparency = null,
    ): string {
        $ihdr = pack('N', $width) . pack('N', $height)
            . chr($bitDepth) . chr($colorType) . "\x00\x00\x00";

        $png = "\x89PNG\r\n\x1a\n" . $this->chunk('IHDR', $ihdr);
        if ($palette !== null) $png .= $this->chunk('PLTE', $palette);
        if ($transparency !== null) $png .= $this->chunk('tRNS', $transparency);
        return $png . $this->chunk('IDAT', $idat) . $this->chunk('IEND', '');
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . "\x00\x00\x00\x00";
    }
}
