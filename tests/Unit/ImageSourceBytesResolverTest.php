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

    /**
     * isAbsolutePath()'s Windows-drive-letter regex used to be malformed
     * (`/^[a-zA-Z]:[\\\/]/`, an escaping mistake that produced an invalid PCRE pattern) and
     * raised a "preg_match(): Unknown modifier ']'" warning on every single call, i.e. for
     * every <img> in every document, since it is reached regardless of whether the source
     * actually looks like a Windows path. It happened to still return the "correct" false for
     * ordinary URLs only because a failed preg_match() also returns false, which masked the
     * bug in normal use; this asserts no warning/error is raised at all, for both a resolver
     * with and without a base directory, and for the actual Windows-path shape the regex is
     * meant to recognize.
     */
    public function testResolvingCommonSourceShapesNeverTriggersAMalformedRegexWarning(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $resolverWithoutBaseDir = new ImageSourceBytesResolver();
            self::assertNull($resolverWithoutBaseDir->resolve('https://example.com/image.png'));
            self::assertNull($resolverWithoutBaseDir->resolve('C:\\Users\\someone\\image.png'));
            self::assertNull($resolverWithoutBaseDir->resolve('C:/Users/someone/image.png'));
            self::assertNull($resolverWithoutBaseDir->resolve('/etc/passwd.png'));
            self::assertNull($resolverWithoutBaseDir->resolve('relative/image.png'));

            $dir = $this->temporaryDirectory();
            try {
                $resolverWithBaseDir = new ImageSourceBytesResolver($dir);
                self::assertNull($resolverWithBaseDir->resolve('https://example.com/image.png'));
                self::assertNull($resolverWithBaseDir->resolve('C:\\Users\\someone\\image.png'));
            } finally {
                @rmdir($dir);
            }
        } finally {
            restore_error_handler();
        }
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
