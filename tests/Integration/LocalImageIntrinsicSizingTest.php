<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class LocalImageIntrinsicSizingTest extends TestCase
{
    public function testFileUrlProvidesIntrinsicDimensionsToLayout(): void
    {
        $path = $this->temporaryPng(320, 180);

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<p style="margin:0"><img src="file://' . $path . '"></p>',
                'viewportWidth' => 500,
                'viewportHeight' => 300,
            ]);

            $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
            self::assertSame(320.0, $box->contentWidth);
            self::assertSame(180.0, $box->contentHeight);
        } finally {
            @unlink($path);
        }
    }

    public function testLocalIntrinsicDimensionsDriveCssAspectRatio(): void
    {
        $path = $this->temporaryPng(320, 180);

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<p style="margin:0"><img src="file://' . $path . '" style="width:160px"></p>',
                'viewportWidth' => 500,
                'viewportHeight' => 300,
            ]);

            $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
            self::assertSame(160.0, $box->contentWidth);
            self::assertSame(90.0, $box->contentHeight);
        } finally {
            @unlink($path);
        }
    }

    private function temporaryPng(int $width, int $height): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pagyra-local-image-');
        if ($path === false) {
            self::fail('Unable to create temporary image file');
        }

        $bytes = "\x89PNG\r\n\x1a\n"
            . pack('N', 13)
            . 'IHDR'
            . pack('N', $width)
            . pack('N', $height)
            . "\x08\x06\x00\x00\x00"
            . "\x00\x00\x00\x00";

        file_put_contents($path, $bytes);
        return $path;
    }
}
