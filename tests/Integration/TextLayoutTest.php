<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TextLayoutTest extends TestCase
{
    public function testParagraphGetsLineBoxAndIntrinsicHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:20px;line-height:1.5;width:200px">Hello world</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 600,
        ]);

        $paragraph = $prepared->layoutRoot->children[0];
        self::assertCount(1, $paragraph->lineBoxes);
        self::assertSame('Hello world', $paragraph->lineBoxes[0]->text);
        self::assertSame(30.0, $paragraph->lineBoxes[0]->height);
        self::assertSame(30.0, $paragraph->box->content->height);
    }

    public function testNarrowParagraphWrapsAndGrowsVertically(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:16px;line-height:1.5;width:70px">hello hello hello</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 600,
        ]);

        // Two lines, not three: with no font-family declared the text is drawn in Times-Roman,
        // where "hello hello" measures 68px and still fits the 70px width. The third line this
        // used to expect came from the per-character estimate overstating that same string.
        $paragraph = $prepared->layoutRoot->children[0];
        self::assertCount(2, $paragraph->lineBoxes);
        self::assertSame(48.0, $paragraph->box->content->height);
        self::assertSame(0.0, $paragraph->lineBoxes[0]->y);
        self::assertSame(24.0, $paragraph->lineBoxes[1]->y);
    }

    public function testHeadingAndParagraphOccupyRealVerticalSpace(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<h1>Título</h1><p>Todo poder emana do povo.</p>',
            'viewportWidth' => 500,
            'viewportHeight' => 700,
        ]);

        $heading = $prepared->layoutRoot->children[0];
        $paragraph = $prepared->layoutRoot->children[1];

        self::assertNotEmpty($heading->lineBoxes);
        self::assertNotEmpty($paragraph->lineBoxes);
        self::assertGreaterThan(0.0, $heading->box->content->height);
        self::assertGreaterThan(0.0, $paragraph->box->content->height);
        self::assertGreaterThan($heading->box->borderBox()->bottom(), $paragraph->box->borderBox()->y);
    }
}
