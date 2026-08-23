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

    public function testPreservesIndexedPaletteAndBuildsSoftMaskFromPackedIndices(): void
    {
        // 4 pixels at 2 bits each: indexes 0, 1, 2, 3 => 00 01 10 11 = 0x1B.
        $compressed = gzcompress("\x00\x1b");
        self::assertIsString($compressed);
        $palette = "\xff\x00\x00" . "\x00\xff\x00" . "\x00\x00\xff" . "\xff\xff\xff";
        $transparency = "\xff\x80\x00\xff";

        $data = (new PngPdfImageParser())->parse(
            $this->png(4, 1, 2, 3, $compressed, 0, $palette, $transparency),
        );

        self::assertNotNull($data);
        self::assertSame(2, $data->bitsPerComponent);
        self::assertSame(1, $data->colors);
        self::assertSame('[/Indexed /DeviceRGB 3 <FF000000FF000000FFFFFFFF>]', $data->colorSpace);
        self::assertTrue($data->usesPngPredictor);
        self::assertSame($compressed, $data->compressedData);
        self::assertNotNull($data->alphaCompressedData);
        self::assertSame("\xff\x80\x00\xff", gzuncompress($data->alphaCompressedData));
    }

    public function testIndexedOpaqueTransparencyDoesNotCreateSoftMask(): void
    {
        $compressed = gzcompress("\x00\x00");
        self::assertIsString($compressed);
        $palette = "\x00\x00\x00" . "\xff\xff\xff";

        $data = (new PngPdfImageParser())->parse(
            $this->png(1, 1, 1, 3, $compressed, 0, $palette, "\xff\xff"),
        );

        self::assertNotNull($data);
        self::assertNull($data->alphaCompressedData);
    }

    public function testRejectsInterlacedPngForNow(): void
    {
        $compressed = gzcompress("\x00\xff\x00\x00");
        self::assertIsString($compressed);

        self::assertNull((new PngPdfImageParser())->parse($this->png(1, 1, 8, 2, $compressed, 1)));
    }

    private function png(
        int $width,
        int $height,
        int $bitDepth,
        int $colorType,
        string $idat,
        int $interlace = 0,
        ?string $palette = null,
        ?string $transparency = null,
    ): string {
        $ihdr = pack('N', $width) . pack('N', $height)
            . chr($bitDepth) . chr($colorType) . "\x00\x00" . chr($interlace);

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
