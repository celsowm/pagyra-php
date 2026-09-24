<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * `<img align="left">`/`<img align="right">` (HTML Standard, rendering §the `img` element) floats
 * the image the same way `style="float:left"` does. It was recognized as a presentational hint
 * for every other element's `text-align` but explicitly skipped for `img`, so the image stayed a
 * plain inline box and the paragraph around it never wrapped alongside it.
 */
final class ImageAlignFloatTest extends TestCase
{
    // 32x16 opaque PNG.
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAQCAYAAAB3AH1ZAAAAG0lEQVRIx2NgGAWjYBSMglEwCkbBKBgFo4B6AAAI+gAB8f6bIgAAAABJRU5ErkJggg==';

    public function testAlignLeftFloatsTheImageToTheLeftEdge(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<div style="margin:0;width:400px"><img src="' . self::PNG . '" align="left"><p style="margin:0">depois</p></div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $container = $prepared->layoutRoot->children[0];
        [$image, $after] = $container->children;
        self::assertSame(0.0, $image->box->content->x);
        self::assertSame(0.0, $image->box->content->y);
        self::assertGreaterThanOrEqual($image->box->content->width, $after->lineBoxes[0]->x);
    }

    public function testAlignRightFloatsTheImageToTheRightEdge(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<div style="margin:0;width:400px"><img src="' . self::PNG . '" align="right"></div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $image = $prepared->layoutRoot->children[0]->children[0];
        self::assertSame(400.0, $image->box->content->x + $image->box->content->width);
    }

    public function testOtherAlignValuesAreLeftAlone(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<div style="margin:0;width:400px"><img src="' . self::PNG . '" align="middle"><span>x</span></div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        // Still inline: the image and the following text share the same line box.
        $line = $prepared->layoutRoot->children[0]->lineBoxes[0] ?? null;
        self::assertNotNull($line);
        self::assertNotEmpty($line->atomicBoxes);
    }

    public function testExplicitFloatStyleStillWinsOverTheAttribute(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<div style="margin:0;width:400px"><img src="' . self::PNG . '" align="left" style="float:right"></div>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $image = $prepared->layoutRoot->children[0]->children[0];
        self::assertSame(400.0, $image->box->content->x + $image->box->content->width);
    }
}
