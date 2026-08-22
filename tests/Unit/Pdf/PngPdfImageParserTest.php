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
        self::assertSame($compressed, $data->compressedData);
    }

    public function testRejectsAlphaPngForNow(): void
    {
        $compressed = gzcompress("\x00\xff\x00\x00\xff");
        self::assertIsString($compressed);

        self::assertNull((new PngPdfImageParser())->parse($this->png(1, 1, 8, 6, $compressed)));
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
