<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Image\ImageMetadataReader;
use PHPUnit\Framework\TestCase;

final class ImageMetadataReaderTest extends TestCase
{
    public function testReadsPngIhdrMetadataWithoutDecodingPixels(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n"
            . pack('N', 13)
            . 'IHDR'
            . pack('N', 320)
            . pack('N', 180)
            . "\x08\x06\x00\x00\x00"
            . "\x00\x00\x00\x00";

        $metadata = (new ImageMetadataReader())->read($bytes);

        self::assertSame(320, $metadata->width);
        self::assertSame(180, $metadata->height);
        self::assertSame('png', $metadata->format);
        self::assertSame(4, $metadata->channels);
        self::assertSame(8, $metadata->bitsPerChannel);
    }

    public function testReadsBaselineJpegSof0Metadata(): void
    {
        $bytes = "\xff\xd8"
            . "\xff\xe0" . pack('n', 4) . "\x00\x00"
            . "\xff\xc0" . pack('n', 17)
            . "\x08" . pack('n', 100) . pack('n', 200) . "\x03"
            . str_repeat("\x00", 9)
            . "\xff\xd9";

        $metadata = (new ImageMetadataReader())->read($bytes);

        self::assertSame(200, $metadata->width);
        self::assertSame(100, $metadata->height);
        self::assertSame('jpeg', $metadata->format);
        self::assertSame(3, $metadata->channels);
        self::assertSame(8, $metadata->bitsPerChannel);
    }

    public function testReadsProgressiveJpegSof2Metadata(): void
    {
        $bytes = "\xff\xd8"
            . "\xff\xc2" . pack('n', 17)
            . "\x08" . pack('n', 720) . pack('n', 1280) . "\x03"
            . str_repeat("\x00", 9)
            . "\xff\xd9";

        $metadata = (new ImageMetadataReader())->read($bytes);

        self::assertSame(1280, $metadata->width);
        self::assertSame(720, $metadata->height);
        self::assertSame('jpeg', $metadata->format);
    }

    public function testReadsWebpVp8xCanvasMetadata(): void
    {
        $data = "\x00\x00\x00\x00"
            . $this->uint24le(639)
            . $this->uint24le(359);

        $metadata = (new ImageMetadataReader())->read($this->webp('VP8X', $data));

        self::assertSame(640, $metadata->width);
        self::assertSame(360, $metadata->height);
        self::assertSame('webp', $metadata->format);
        self::assertSame(4, $metadata->channels);
    }

    public function testReadsWebpVp8lMetadata(): void
    {
        $width = 321;
        $height = 123;
        $bits = ($width - 1) | (($height - 1) << 14);
        $data = "\x2f" . pack('V', $bits);

        $metadata = (new ImageMetadataReader())->read($this->webp('VP8L', $data));

        self::assertSame($width, $metadata->width);
        self::assertSame($height, $metadata->height);
        self::assertSame('webp', $metadata->format);
        self::assertSame(4, $metadata->channels);
    }

    public function testReadsLossyWebpVp8FrameMetadataWithoutPixelDecode(): void
    {
        $data = "\x00\x00\x00"
            . "\x9d\x01\x2a"
            . pack('v', 800)
            . pack('v', 450);

        $metadata = (new ImageMetadataReader())->read($this->webp('VP8 ', $data));

        self::assertSame(800, $metadata->width);
        self::assertSame(450, $metadata->height);
        self::assertSame('webp', $metadata->format);
        self::assertSame(3, $metadata->channels);
    }

    public function testRejectsMalformedWebpChunkLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $bytes = 'RIFF' . pack('V', 100) . 'WEBP' . 'VP8X' . pack('V', 100) . "\x00";
        (new ImageMetadataReader())->read($bytes);
    }

    public function testRejectsUnsupportedData(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ImageMetadataReader())->read('not an image');
    }

    public function testRejectsPngWithoutIhdr(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ImageMetadataReader())->read("\x89PNG\r\n\x1a\n" . str_repeat("\x00", 25));
    }

    private function webp(string $fourCc, string $data): string
    {
        $chunk = $fourCc . pack('V', strlen($data)) . $data;
        if ((strlen($data) % 2) === 1) {
            $chunk .= "\x00";
        }

        return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
    }

    private function uint24le(int $value): string
    {
        return chr($value & 0xff)
            . chr(($value >> 8) & 0xff)
            . chr(($value >> 16) & 0xff);
    }
}
