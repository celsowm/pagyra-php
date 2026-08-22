<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class InlineBlockIntrinsicSizingTest extends TestCase
{
    public function testImageCssWidthPreservesIntrinsicAspectRatio(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img width="200" height="100" style="width:100px"></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        self::assertSame(100.0, $box->contentWidth);
        self::assertSame(50.0, $box->contentHeight);
    }

    public function testImageCssHeightPreservesIntrinsicAspectRatio(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img width="200" height="100" style="height:25px"></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        self::assertSame(50.0, $box->contentWidth);
        self::assertSame(25.0, $box->contentHeight);
    }

    public function testImageMaxWidthScalesAutomaticHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><img width="200" height="100" style="max-width:80px"></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        self::assertSame(80.0, $box->contentWidth);
        self::assertSame(40.0, $box->contentHeight);
    }

    public function testInlineBlockAutoWidthUsesInternalContent(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:16px"><span style="display:inline-block">Hello world</span></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        self::assertGreaterThan(0.0, $box->contentWidth);
        self::assertGreaterThan(0.0, $box->contentHeight);
        self::assertNotEmpty($box->contentLines);
        self::assertSame('Hello world', $box->contentLines[0]->text);
        self::assertEqualsWithDelta($box->contentWidth, $box->contentLines[0]->width, 0.0001);
    }

    public function testInlineBlockExplicitWidthWrapsInternalContentAndGrowsHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:16px"><span style="display:inline-block;width:45px">hello hello hello</span></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        self::assertSame(45.0, $box->contentWidth);
        self::assertGreaterThanOrEqual(2, count($box->contentLines));
        self::assertEqualsWithDelta(
            array_sum(array_map(static fn($line): float => $line->height, $box->contentLines)),
            $box->contentHeight,
            0.0001,
        );
    }

    public function testInternalContentCoordinatesAreTranslatedIntoAtomicBox(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0"><span style="display:inline-block;padding:3px 5px;border-width:1px;margin:2px">X</span></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        $line = $box->contentLines[0];
        self::assertEqualsWithDelta(
            $box->x + $box->margin['left'] + $box->border['left'] + $box->padding['left'],
            $line->x,
            0.0001,
        );
        self::assertEqualsWithDelta(
            $box->y + $box->margin['top'] + $box->border['top'] + $box->padding['top'],
            $line->y,
            0.0001,
        );
    }
}
