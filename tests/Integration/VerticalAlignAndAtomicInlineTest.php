<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class VerticalAlignAndAtomicInlineTest extends TestCase
{
    public function testSuperAndSubShiftTheirOwnBaselines(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:20px;line-height:24px">A<span style="vertical-align:super">B</span><span style="vertical-align:sub">C</span></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        self::assertCount(3, $line->runs);

        [$normal, $super, $sub] = $line->runs;
        self::assertSame('A', $normal->text);
        self::assertSame('B', $super->text);
        self::assertSame('C', $sub->text);
        self::assertLessThan($normal->baseline, $super->baseline);
        self::assertGreaterThan($normal->baseline, $sub->baseline);
        self::assertGreaterThan(24.0, $line->height);
    }

    public function testFallbackBaselineUsesAscentAndHalfLeading(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:20px;line-height:30px">A</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        // (30 - 20) / 2 half-leading + 20 * .75 ascent = 20px from line top.
        self::assertEqualsWithDelta($line->y + 20.0, $line->baseline, 0.0001);
    }

    public function testNumericVerticalAlignMovesRunUp(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:20px;line-height:24px">A<span style="vertical-align:5px">B</span></p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        self::assertCount(2, $line->runs);
        self::assertEqualsWithDelta(5.0, $line->runs[0]->baseline - $line->runs[1]->baseline, 0.0001);
    }

    public function testImageBecomesAtomicInlineBox(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;font-size:16px;line-height:20px">A<img width="24" height="30" style="vertical-align:middle">B</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $line = $prepared->layoutRoot->children[0]->lineBoxes[0];
        self::assertCount(1, $line->atomicBoxes);
        self::assertSame(24.0, $line->atomicBoxes[0]->width);
        self::assertSame(30.0, $line->atomicBoxes[0]->height);
        self::assertSame(24.0, $line->atomicBoxes[0]->contentWidth);
        self::assertSame(30.0, $line->atomicBoxes[0]->contentHeight);
        self::assertGreaterThanOrEqual(30.0, $line->height);
        self::assertSame('AB', $line->text);
    }

    public function testAtomicInlineBoxUsesMarginPaddingAndBorderInOuterSize(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;width:200px;font-size:16px">A<span style="display:inline-block;width:20px;height:10px;margin:2px 3px;padding:4px 5px;border-width:1px 2px;border-style:solid">X</span>B</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $box = $prepared->layoutRoot->children[0]->lineBoxes[0]->atomicBoxes[0];
        self::assertSame(20.0, $box->contentWidth);
        self::assertSame(10.0, $box->contentHeight);
        self::assertSame(['top' => 2.0, 'right' => 3.0, 'bottom' => 2.0, 'left' => 3.0], $box->margin);
        self::assertSame(['top' => 4.0, 'right' => 5.0, 'bottom' => 4.0, 'left' => 5.0], $box->padding);
        self::assertSame(['top' => 1.0, 'right' => 2.0, 'bottom' => 1.0, 'left' => 2.0], $box->border);
        self::assertSame(40.0, $box->width);
        self::assertSame(24.0, $box->height);
    }

    public function testAtomicInlineBoxOuterWidthParticipatesInWrapping(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;width:45px;font-size:16px">A<span style="display:inline-block;width:20px;height:10px;margin:0 4px;padding:0 5px;border-width:0 1px"></span>B</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $lines = $prepared->layoutRoot->children[0]->lineBoxes;
        $boxes = array_sum(array_map(static fn($line): int => count($line->atomicBoxes), $lines));
        self::assertSame(1, $boxes);
        self::assertGreaterThanOrEqual(2, count($lines));
    }

    public function testAtomicInlineBoxParticipatesInWrapping(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<p style="margin:0;width:45px;font-size:16px">AA<img width="40" height="10">BB</p>',
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        $lines = $prepared->layoutRoot->children[0]->lineBoxes;
        self::assertGreaterThanOrEqual(2, count($lines));
        self::assertSame(1, array_sum(array_map(static fn($line): int => count($line->atomicBoxes), $lines)));
    }
}
