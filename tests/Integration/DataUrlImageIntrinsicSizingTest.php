<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class DataUrlImageIntrinsicSizingTest extends TestCase
{
    public function testPngDataUrlProvidesIntrinsicDimensionsWithoutHtmlAttributes(): void
    {
        $src = $this->pngDataUrl(320, 180);
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img src="' . $src . '"></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(320.0, $box->contentWidth);
        self::assertSame(180.0, $box->contentHeight);
        self::assertSame(320.0, $box->source->node->intrinsicWidth);
        self::assertSame(180.0, $box->source->node->intrinsicHeight);
    }

    public function testCssWidthUsesDataUrlIntrinsicAspectRatio(): void
    {
        $src = $this->pngDataUrl(320, 180);
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:160px"></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(160.0, $box->contentWidth);
        self::assertSame(90.0, $box->contentHeight);
    }

    public function testIntrinsicMetadataDoesNotCreateSourceWidthAttributeForCssSelectors(): void
    {
        $src = $this->pngDataUrl(320, 180);
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>img[width]{width:10px}</style><p style="margin:0"><img src="' . $src . '"></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertArrayNotHasKey('width', $box->source->node->attributes);
        self::assertSame(320.0, $box->contentWidth);
        self::assertSame(180.0, $box->contentHeight);
    }

    public function testHtmlWidthAttributeStillOverridesDecodedIntrinsicWidthAsIntrinsicInput(): void
    {
        $src = $this->pngDataUrl(320, 180);
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img src="' . $src . '" width="200" height="100" style="width:100px"></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(100.0, $box->contentWidth);
        self::assertSame(50.0, $box->contentHeight);
        self::assertSame('200', $box->source->node->attributes['width']);
        self::assertSame('100', $box->source->node->attributes['height']);
    }

    private function pngDataUrl(int $width, int $height): string
    {
        $bytes = "\x89PNG\r\n\x1a\n"
            . pack('N', 13)
            . 'IHDR'
            . pack('N', $width)
            . pack('N', $height)
            . "\x08\x06\x00\x00\x00"
            . "\x00\x00\x00\x00";

        return 'data:image/png;base64,' . base64_encode($bytes);
    }
}
