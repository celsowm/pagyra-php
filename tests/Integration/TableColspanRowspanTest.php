<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TableColspanRowspanTest extends TestCase
{
    public function testColspanCellSpansTheCombinedWidthOfTheColumnsItCovers(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table style="width:300px">'
                . '<tr><td colspan="2" style="padding:0">span</td></tr>'
                . '<tr><td style="padding:0">a</td><td style="padding:0">b</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $table = $prepared->layoutRoot->children[0];
        [$spanRow, $normalRow] = $table->children;

        self::assertCount(1, $spanRow->children);
        self::assertCount(2, $normalRow->children);
        [$a, $b] = $normalRow->children;

        self::assertEqualsWithDelta($a->box->content->width + $b->box->content->width, $spanRow->children[0]->box->content->width, 0.5);
    }

    public function testColspanCellDoesNotCompressTheCellsInFollowingRows(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table style="width:300px">'
                . '<tr><td colspan="2" style="padding:0">span</td></tr>'
                . '<tr><td style="padding:0">a</td><td style="padding:0">b</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $normalRow = $prepared->layoutRoot->children[0]->children[1];
        self::assertCount(2, $normalRow->children);
        [$a, $b] = $normalRow->children;
        self::assertGreaterThan($a->box->content->x + $a->box->content->width - 0.01, $b->box->content->x);
    }

    public function testRowspanCellSpansTheCombinedHeightOfTheRowsItCoversAndKeepsThemAsSeparateRows(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table>'
                . '<tr><td rowspan="2" style="padding:0;height:5px">tall</td><td style="padding:0;height:20px">a</td></tr>'
                . '<tr><td style="padding:0;height:30px">b</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 300,
        ]);

        $table = $prepared->layoutRoot->children[0];
        self::assertCount(2, $table->children);
        [$firstRow, $secondRow] = $table->children;

        self::assertCount(2, $firstRow->children);
        self::assertCount(1, $secondRow->children);

        $tall = $firstRow->children[0];
        self::assertEqualsWithDelta($firstRow->box->content->height + $secondRow->box->content->height, $tall->box->borderBox()->height, 0.5);
        self::assertEqualsWithDelta($firstRow->box->content->y, $tall->box->content->y, 0.01);
    }

    public function testInvalidOrZeroSpanValuesFallBackToOneInsteadOfBreakingTheGrid(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table>'
                . '<tr><td colspan="0" style="padding:0">a</td><td rowspan="abc" style="padding:0">b</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        self::assertCount(2, $row->children);
    }
}
