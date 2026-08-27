<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit;

use Pagyra\Core\RenderHtmlOptions;
use PHPUnit\Framework\TestCase;

final class RenderHtmlOptionsResourceBaseTest extends TestCase
{
    public function testAcceptsAbsoluteResourceBaseDirectory(): void
    {
        $options = RenderHtmlOptions::fromArray([
            'html' => '<p>x</p>',
            'resourceBaseDir' => '/tmp/assets',
        ]);

        self::assertSame('/tmp/assets', $options->resourceBaseDir);
    }

    public function testAcceptsFileUrlResourceBaseDirectory(): void
    {
        $options = RenderHtmlOptions::fromArray([
            'html' => '<p>x</p>',
            'resourceBaseDir' => 'file:///tmp/assets',
        ]);

        self::assertSame('file:///tmp/assets', $options->resourceBaseDir);
    }

    public function testRejectsRelativeResourceBaseDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resourceBaseDir must be an absolute local path or file:// URL');

        RenderHtmlOptions::fromArray([
            'html' => '<p>x</p>',
            'resourceBaseDir' => 'assets',
        ]);
    }

    public function testRejectsNonStringResourceBaseDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('resourceBaseDir must be a string or null');

        RenderHtmlOptions::fromArray([
            'html' => '<p>x</p>',
            'resourceBaseDir' => 123,
        ]);
    }

    /**
     * isAbsoluteResourceBase()'s Windows-drive-letter regex used to be malformed and raised a
     * "preg_match(): Unknown modifier ']'" warning on every resourceBaseDir given, regardless
     * of its shape. See the identical fix/regression test in ImageSourceBytesResolverTest.
     */
    public function testValidatingAResourceBaseDirectoryNeverTriggersAMalformedRegexWarning(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            self::assertSame('/tmp/assets', RenderHtmlOptions::fromArray([
                'html' => '<p>x</p>',
                'resourceBaseDir' => '/tmp/assets',
            ])->resourceBaseDir);

            self::assertSame('C:\\assets', RenderHtmlOptions::fromArray([
                'html' => '<p>x</p>',
                'resourceBaseDir' => 'C:\\assets',
            ])->resourceBaseDir);
        } finally {
            restore_error_handler();
        }
    }
}
