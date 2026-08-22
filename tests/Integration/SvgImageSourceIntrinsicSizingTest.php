<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class SvgImageSourceIntrinsicSizingTest extends TestCase
{
    public function testSvgDataUrlProvidesIntrinsicDimensionsToImg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180"></svg>';
        $src = 'data:image/svg+xml;base64,' . base64_encode($svg);

        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img src="' . $src . '"></p>',
            'viewportWidth' => 500,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(320.0, $box->contentWidth);
        self::assertSame(180.0, $box->contentHeight);
    }

    public function testCssWidthPreservesSvgSourceAspectRatio(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 200"></svg>';
        $src = 'data:image/svg+xml,' . rawurlencode($svg);

        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img src="' . $src . '" style="width:160px"></p>',
            'viewportWidth' => 500,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(160.0, $box->contentWidth);
        self::assertSame(80.0, $box->contentHeight);
    }

    public function testLocalSvgFileProvidesIntrinsicDimensions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pagyra-svg-');
        if ($path === false) {
            self::fail('Unable to create temporary SVG file');
        }

        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="120"></svg>');

        try {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => '<p style="margin:0"><img src="file://' . $path . '"></p>',
                'viewportWidth' => 500,
                'viewportHeight' => 300,
            ]);

            $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

            self::assertSame(240.0, $box->contentWidth);
            self::assertSame(120.0, $box->contentHeight);
        } finally {
            @unlink($path);
        }
    }
}
