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
        self::assertGreaterThanOrEqual(30.0, $line->height);
        self::assertSame('AB', $line->text);
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
