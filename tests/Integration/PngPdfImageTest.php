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

    private function png(int $width, int $height, int $bitDepth, int $colorType, string $idat): string
    {
        $ihdr = pack('N', $width) . pack('N', $height)
            . chr($bitDepth) . chr($colorType) . "\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n"
            . $this->chunk('IHDR', $ihdr)
            . $this->chunk('IDAT', $idat)
            . $this->chunk('IEND', '');
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . "\x00\x00\x00\x00";
    }
}
