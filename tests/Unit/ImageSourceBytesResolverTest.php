<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Image\ImageSourceBytesResolver;
use PHPUnit\Framework\TestCase;

final class ImageSourceBytesResolverTest extends TestCase
{
    public function testResolvesRelativeFileFromExplicitBaseDirectory(): void
    {
        $dir = $this->temporaryDirectory();
        $nested = $dir . DIRECTORY_SEPARATOR . 'images';
        mkdir($nested);
        $path = $nested . DIRECTORY_SEPARATOR . 'sample.bin';
        file_put_contents($path, 'abc');

        try {
            $resolver = new ImageSourceBytesResolver($dir);
            self::assertSame('abc', $resolver->resolve('images/sample.bin'));
        } finally {
            @unlink($path);
            @rmdir($nested);
            @rmdir($dir);
        }
    }

    public function testDecodesPercentEncodedRelativeFileName(): void
    {
        $dir = $this->temporaryDirectory();
        $path = $dir . DIRECTORY_SEPARATOR . 'my image.bin';
        file_put_contents($path, 'payload');

        try {
            $resolver = new ImageSourceBytesResolver($dir);
            self::assertSame('payload', $resolver->resolve('my%20image.bin'));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }

    public function testDoesNotTreatRemoteUrlAsRelativeLocalFile(): void
    {
        $dir = $this->temporaryDirectory();

        try {
            $resolver = new ImageSourceBytesResolver($dir);
            self::assertNull($resolver->resolve('https://example.com/image.png'));
        } finally {
            @rmdir($dir);
        }
    }

    public function testRelativeFileStillNeedsExplicitBaseDirectory(): void
    {
        self::assertNull((new ImageSourceBytesResolver())->resolve('images/sample.png'));
    }

    private function temporaryDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pagyra-bytes-');
        if ($path === false) {
            self::fail('Unable to allocate temporary path');
        }
        @unlink($path);
        if (!mkdir($path)) {
            self::fail('Unable to create temporary directory');
        }
        return $path;
    }
}
