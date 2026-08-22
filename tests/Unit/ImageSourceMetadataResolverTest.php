<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Image\ImageSourceMetadataResolver;
use PHPUnit\Framework\TestCase;

final class ImageSourceMetadataResolverTest extends TestCase
{
    public function testResolvesPngDataUrl(): void
    {
        $bytes = $this->pngBytes(320, 180);

        $metadata = (new ImageSourceMetadataResolver())->resolve(
            'data:image/png;base64,' . base64_encode($bytes),
        );

        self::assertNotNull($metadata);
        self::assertSame(320, $metadata->width);
        self::assertSame(180, $metadata->height);
        self::assertSame('png', $metadata->format);
    }

    public function testResolvesProgressiveJpegDataUrl(): void
    {
        $bytes = "\xff\xd8"
            . "\xff\xe0\x00\x04\x00\x00"
            . "\xff\xc2\x00\x11\x08\x02\xd0\x05\x00\x03"
            . str_repeat("\x00", 9)
            . "\xff\xd9";

        $metadata = (new ImageSourceMetadataResolver())->resolve(
            'data:image/jpeg;base64,' . base64_encode($bytes),
        );

        self::assertNotNull($metadata);
        self::assertSame(1280, $metadata->width);
        self::assertSame(720, $metadata->height);
        self::assertSame('jpeg', $metadata->format);
    }

    public function testResolvesAbsoluteLocalFile(): void
    {
        $path = $this->temporaryPng(640, 360);

        try {
            $metadata = (new ImageSourceMetadataResolver())->resolve($path);

            self::assertNotNull($metadata);
            self::assertSame(640, $metadata->width);
            self::assertSame(360, $metadata->height);
        } finally {
            @unlink($path);
        }
    }

    public function testResolvesFileUrl(): void
    {
        $path = $this->temporaryPng(48, 24);

        try {
            $metadata = (new ImageSourceMetadataResolver())->resolve('file://' . $path);

            self::assertNotNull($metadata);
            self::assertSame(48, $metadata->width);
            self::assertSame(24, $metadata->height);
        } finally {
            @unlink($path);
        }
    }

    public function testDoesNotResolveRelativePathWithoutBaseDirectory(): void
    {
        self::assertNull((new ImageSourceMetadataResolver())->resolve('images/example.png'));
    }

    public function testReturnsNullForUnsupportedOrMalformedSources(): void
    {
        $resolver = new ImageSourceMetadataResolver();

        self::assertNull($resolver->resolve(null));
        self::assertNull($resolver->resolve('https://example.com/image.png'));
        self::assertNull($resolver->resolve('data:image/png;base64,%%%'));
        self::assertNull($resolver->resolve('data:text/plain;base64,SGVsbG8='));
    }

    private function temporaryPng(int $width, int $height): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pagyra-image-');
        if ($path === false) {
            self::fail('Unable to create temporary image file');
        }

        file_put_contents($path, $this->pngBytes($width, $height));
        return $path;
    }

    private function pngBytes(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n"
            . pack('N', 13)
            . 'IHDR'
            . pack('N', $width)
            . pack('N', $height)
            . "\x08\x06\x00\x00\x00"
            . "\x00\x00\x00\x00";
    }
}
