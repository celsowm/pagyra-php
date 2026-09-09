<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * `text-indent` is inherited, so it narrows the first line of an inline-block's own layout too.
 * The shrink-to-fit width has to carry it: measuring the box without the indent and then laying
 * its content out with the indent sizes the box for a single line and then wraps the content
 * inside it, which pushed a line of text out of reading order.
 */
final class InlineBlockIntrinsicWidthCarriesTextIndentTest extends TestCase
{
    private function firstBlock(string $html): \Pagyra\Layout\LayoutNode
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 300,
            'viewportHeight' => 400,
        ]);

        return $prepared->layoutRoot->children[0];
    }

    public function testInheritedIndentWidensTheBoxInsteadOfWrappingIt(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px;text-indent:40px">'
            . 'A <span style="display:inline-block">alfa beta gama</span> Z'
            . '</p>',
        );

        $box = $paragraph->lineBoxes[0]->atomicBoxes[0];
        self::assertCount(1, $box->contentLines);
        self::assertSame('alfa beta gama', trim($box->contentLines[0]->text));
        self::assertSame(20.0, $box->height);
    }

    public function testTheIndentIsTheWholeDifferenceInWidth(): void
    {
        $comIndent = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px;text-indent:40px">'
            . 'A <span style="display:inline-block">alfa beta gama</span> Z'
            . '</p>',
        )->lineBoxes[0]->atomicBoxes[0];

        $semIndent = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px">'
            . 'A <span style="display:inline-block">alfa beta gama</span> Z'
            . '</p>',
        )->lineBoxes[0]->atomicBoxes[0];

        self::assertEqualsWithDelta(40.0, $comIndent->contentWidth - $semIndent->contentWidth, 1e-9);
    }

    public function testABoxThatOptsOutOfTheIndentIsNotWidened(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px;text-indent:40px">'
            . 'A <span style="display:inline-block;text-indent:0">alfa beta gama</span> Z'
            . '</p>',
        );

        $box = $paragraph->lineBoxes[0]->atomicBoxes[0];
        self::assertCount(1, $box->contentLines);
        self::assertLessThan(60.0, $box->contentWidth);
    }

    public function testTheParagraphItselfStillIndentsOnlyItsFirstLine(): void
    {
        $paragraph = $this->firstBlock(
            '<p style="margin:0;font-size:10px;line-height:20px;text-indent:40px">'
            . 'alfa beta gama delta epsilon zeta eta theta iota kappa lambda mu nu xi omicron pi rho sigma tau'
            . '</p>',
        );

        self::assertCount(2, $paragraph->lineBoxes);
        self::assertSame(40.0, $paragraph->lineBoxes[0]->x);
        self::assertSame(0.0, $paragraph->lineBoxes[1]->x);
    }
}
