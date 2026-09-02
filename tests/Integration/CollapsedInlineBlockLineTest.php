<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * A `display:inline-block; height:0` box alone on its line (the shape court-document
 * headers use to float a brasão out of flow) must not leave a font-strut-tall phantom
 * line that pushes the following block down. Real text, or an atomic box with height,
 * still gets the strut.
 */
final class CollapsedInlineBlockLineTest extends TestCase
{
    private function firstBlock(string $html): \Pagyra\Layout\LayoutNode
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 600,
            'viewportHeight' => 400,
        ]);

        return $prepared->layoutRoot->children[0];
    }

    public function testZeroHeightInlineBlockLineAddsNoStrut(): void
    {
        $wrapper = $this->firstBlock(
            '<div style="font-size:16px;line-height:20px">'
            . '<span style="display:inline-block;height:0;width:100%"></span>'
            . '<p style="margin:0">text</p>'
            . '</div>',
        );

        // wrapper: [anonymous inline line for the span] then the <p>
        self::assertSame(0.0, $wrapper->lineBoxes[0]->height);
        self::assertSame(0.0, $wrapper->children[0]->box->content->y);
    }

    public function testInlineBlockWithHeightStillGetsALineBox(): void
    {
        $wrapper = $this->firstBlock(
            '<div style="font-size:16px;line-height:20px">'
            . '<span style="display:inline-block;height:12px;width:100%"></span>'
            . '<p style="margin:0">text</p>'
            . '</div>',
        );

        self::assertGreaterThanOrEqual(12.0, $wrapper->lineBoxes[0]->height);
        self::assertGreaterThanOrEqual(12.0, $wrapper->children[0]->box->content->y);
    }

    public function testConsecutiveBrStillProducesAStrutTallEmptyLine(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px">a<br/><br/>b</p>',
        );

        self::assertCount(3, $paragraph->lineBoxes);
        self::assertSame(20.0, $paragraph->lineBoxes[1]->height);
    }
}
