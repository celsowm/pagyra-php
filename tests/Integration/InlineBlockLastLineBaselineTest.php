<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * CSS 2.1 10.8.1: an inline-block aligns by the baseline of its *last* in-flow line box.
 * Taking the first one put a box that wraps internally one line too low, so its opening
 * line sat on the parent's baseline and everything after it spilled underneath, out of
 * reading order. A box with no in-flow line box (a replaced element, an empty one) has no
 * baseline to take and keeps sitting on the line top.
 */
final class InlineBlockLastLineBaselineTest extends TestCase
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

    public function testWrappedInlineBlockAlignsByItsLastLine(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px">'
            . 'A <span style="display:inline-block;width:22px">alfa beta</span> Z'
            . '</p>',
        );

        self::assertCount(1, $paragraph->lineBoxes);
        $line = $paragraph->lineBoxes[0];
        $box = $line->atomicBoxes[0];
        self::assertCount(2, $box->contentLines, 'the box has to wrap for this to mean anything');

        $last = $box->contentLines[1];
        self::assertSame('beta', trim($last->text));
        self::assertEqualsWithDelta($line->baseline, $last->baseline, 1e-9);

        // and the first line is the one that rides above, not below
        self::assertLessThan($line->baseline, $box->contentLines[0]->baseline);
    }

    public function testSurroundingTextSitsOnThatSameBaseline(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px">'
            . 'A <span style="display:inline-block;width:22px">alfa beta</span> Z'
            . '</p>',
        );

        $line = $paragraph->lineBoxes[0];
        $last = $line->atomicBoxes[0]->contentLines[1];
        foreach ($line->runs as $run) {
            self::assertEqualsWithDelta($last->baseline, $run->baseline, 1e-9, "run \"{$run->text}\"");
        }
    }

    public function testLineGrowsToHoldTheWholeBox(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px">'
            . 'A <span style="display:inline-block;width:22px">alfa beta</span> Z'
            . '</p>',
        );

        $line = $paragraph->lineBoxes[0];
        self::assertSame(40.0, $line->height);
        self::assertSame(0.0, $line->atomicBoxes[0]->y);
    }

    public function testSingleLineInlineBlockStillSitsOnTheLine(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px">'
            . 'A <span style="display:inline-block">alfa</span> Z'
            . '</p>',
        );

        $line = $paragraph->lineBoxes[0];
        self::assertSame(20.0, $line->height);
        self::assertSame(0.0, $line->atomicBoxes[0]->y);
        self::assertEqualsWithDelta(
            $line->baseline,
            $line->atomicBoxes[0]->contentLines[0]->baseline,
            1e-9,
        );
    }

    public function testBoxPaddingAndBorderPushTheBaselineDown(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px">'
            . 'A <span style="display:inline-block;padding-top:5px;border-top:3px solid #000">alfa</span> Z'
            . '</p>',
        );

        $line = $paragraph->lineBoxes[0];
        $box = $line->atomicBoxes[0];
        // The 3px border and 5px padding sit above the box's own line, so its baseline is 8px
        // further from the box top than the strut's is from the line top. The box is already
        // the topmost item, so the line absorbs that by dropping the text 8px and growing.
        self::assertSame(28.0, $line->height);
        self::assertSame(0.0, $box->y);
        self::assertEqualsWithDelta($line->baseline, $box->contentLines[0]->baseline, 1e-9);
        foreach ($line->runs as $run) {
            self::assertSame(8.0, $run->y, "run \"{$run->text}\"");
        }
    }

    public function testEmptyInlineBlockHasNoBaselineToTake(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px">'
            . 'A <span style="display:inline-block;width:30px;height:30px"></span> Z'
            . '</p>',
        );

        $line = $paragraph->lineBoxes[0];
        self::assertSame([], $line->atomicBoxes[0]->contentLines);
        self::assertSame(0.0, $line->atomicBoxes[0]->y);
    }
}
