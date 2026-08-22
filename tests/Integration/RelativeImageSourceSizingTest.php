<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class RelativeImageSourceSizingTest extends TestCase
{
    public function testRelativePngUsesExplicitResourceBaseDirectory(): void
    {
        $dir = $this->temporaryDirectory();
        $images = $dir . DIRECTORY_SEPARATOR . 'images';
        mkdir($images);
        file_put_contents($images . DIRECTORY_SEPARATOR . 'hero.png', $this->pngBytes(640, 360));

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<p style="margin:0"><img src="images/hero.png" style="width:320px"></p>',
                'resourceBaseDir' => $dir,
                'viewportWidth' => 800,
                'viewportHeight' => 600,
            ]);

            $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
            self::assertSame(320.0, $box->contentWidth);
            self::assertSame(180.0, $box->contentHeight);
        } finally {
            @unlink($images . DIRECTORY_SEPARATOR . 'hero.png');
            @rmdir($images);
            @rmdir($dir);
        }
    }

    public function testRelativeSvgUsesExplicitFileUrlResourceBase(): void
    {
        $dir = $this->temporaryDirectory();
        $assets = $dir . DIRECTORY_SEPARATOR . 'assets';
        mkdir($assets);
        file_put_contents(
            $assets . DIRECTORY_SEPARATOR . 'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 120"></svg>',
        );

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<p style="margin:0"><img src="assets/logo.svg" style="width:150px"></p>',
                'resourceBaseDir' => 'file://' . $dir,
                'viewportWidth' => 400,
                'viewportHeight' => 300,
            ]);

            $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
            self::assertSame(150.0, $box->contentWidth);
            self::assertSame(60.0, $box->contentHeight);
        } finally {
            @unlink($assets . DIRECTORY_SEPARATOR . 'logo.svg');
            @rmdir($assets);
            @rmdir($dir);
        }
    }

    public function testRelativeSourceStillHasNoDecodedIntrinsicSizeWithoutBaseDirectory(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img src="images/missing.png"></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        self::assertNull($box->source->node->intrinsicWidth);
        self::assertNull($box->source->node->intrinsicHeight);
    }

    private function temporaryDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pagyra-resources-');
        if ($path === false) {
            self::fail('Unable to allocate temporary path');
        }
        @unlink($path);
        if (!mkdir($path)) {
            self::fail('Unable to create temporary directory');
        }
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
