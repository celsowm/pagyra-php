<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class SvgIntrinsicSizingTest extends TestCase
{
    public function testViewBoxProvidesIntrinsicDimensions(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><svg viewBox="0 0 400 200"><rect width="400" height="200"/></svg></p>',
            'viewportWidth' => 500,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertTrue($box->source->node->isSvg());
        self::assertSame(400.0, $box->contentWidth);
        self::assertSame(200.0, $box->contentHeight);
        self::assertSame(400.0, $box->source->node->intrinsicWidth);
        self::assertSame(200.0, $box->source->node->intrinsicHeight);
    }

    public function testCssWidthPreservesViewBoxAspectRatio(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><svg viewBox="0 0 400 200" style="width:160px"></svg></p>',
            'viewportWidth' => 500,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(160.0, $box->contentWidth);
        self::assertSame(80.0, $box->contentHeight);
    }

    public function testExplicitSvgDimensionsTakePriorityOverViewBoxFallbacks(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><svg width="120" height="60" viewBox="0 0 400 200"></svg></p>',
            'viewportWidth' => 500,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(120.0, $box->contentWidth);
        self::assertSame(60.0, $box->contentHeight);
        self::assertSame('120', $box->source->node->attributes['width']);
        self::assertSame('60', $box->source->node->attributes['height']);
    }

    public function testSvgWithoutDimensionsFallsBackToReferenceDefault(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><svg></svg></p>',
            'viewportWidth' => 500,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertSame(100.0, $box->contentWidth);
        self::assertSame(100.0, $box->contentHeight);
    }

    public function testDerivedSvgWidthDoesNotCreateWidthAttributeSelectorMatch(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>svg[width]{width:10px}</style><p style="margin:0"><svg viewBox="0 0 300 150"></svg></p>',
            'viewportWidth' => 500,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];

        self::assertArrayNotHasKey('width', $box->source->node->attributes);
        self::assertSame(300.0, $box->contentWidth);
        self::assertSame(150.0, $box->contentHeight);
    }
}
