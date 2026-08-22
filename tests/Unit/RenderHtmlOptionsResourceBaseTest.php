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
}
