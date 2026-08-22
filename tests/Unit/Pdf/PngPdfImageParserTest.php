<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Pdf;

use Pagyra\Pdf\PngPdfImageParser;
use PHPUnit\Framework\TestCase;

final class PngPdfImageParserTest extends TestCase
{
    public function testExtractsRgbIdatForPdfPredictor(): void
    {
        $compressed = gzcompress("\x00\xff\x00\x00");
        self::assertIsString($compressed);

        $png = $this->png(1, 1, 8, 2, $compressed);
        $data = (new PngPdfImageParser())->parse($png);

        self::assertNotNull($data);
        self::assertSame(1, $data->width);
        self::assertSame(1, $data->height);
        self::assertSame(8, $data->bitsPerComponent);
        self::assertSame(3, $data->colors);
        self::assertSame('/DeviceRGB', $data->colorSpace);
        self::assertTrue($data->usesPngPredictor);
        self::assertNull($data->alphaCompressedData);
        self::assertSame($compressed, $data->compressedData);
    }

    public function testSeparatesRgbaIntoRgbAndSoftMaskStreams(): void
    {
        $compressed = gzcompress("\x00\xff\x00\x00\x80");
        self::assertIsString($compressed);

        $data = (new PngPdfImageParser())->parse($this->png(1, 1, 8, 6, $compressed));

        self::assertNotNull($data);
        self::assertSame('/DeviceRGB', $data->colorSpace);
        self::assertSame(3, $data->colors);
        self::assertFalse($data->usesPngPredictor);
        self::assertNotNull($data->alphaCompressedData);
        self::assertSame("\xff\x00\x00", gzuncompress($data->compressedData));
        self::assertSame("\x80", gzuncompress($data->alphaCompressedData));
    }

    public function testRejectsInterlacedPngForNow(): void
    {
        $compressed = gzcompress("\x00\xff\x00\x00");
        self::assertIsString($compressed);

        self::assertNull((new PngPdfImageParser())->parse($this->png(1, 1, 8, 2, $compressed, 1)));
    }

    private function png(int $width, int $height, int $bitDepth, int $colorType, string $idat, int $interlace = 0): string
    {
        $ihdr = pack('N', $width) . pack('N', $height)
            . chr($bitDepth) . chr($colorType) . "\x00\x00" . chr($interlace);

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
