<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TableLayoutTest extends TestCase
{
    public function testCellsInARowAreLaidOutSideBySideNotConcatenatedTogether(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table style="border-spacing:0"><tr><td style="padding:0">a</td><td style="padding:0">bbbbbbbbbb</td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $table = $prepared->layoutRoot->children[0];
        $row = $table->children[0];
        self::assertCount(2, $row->children);
        [$first, $second] = $row->children;

        self::assertSame(0.0, $first->box->content->y);
        self::assertSame(0.0, $second->box->content->y);
        self::assertSame(0.0, $first->box->content->x);
        self::assertGreaterThan($first->box->content->x + $first->box->content->width - 0.01, $second->box->content->x);
        self::assertSame('a', $first->lineBoxes[0]->text);
        self::assertSame('bbbbbbbbbb', $second->lineBoxes[0]->text);
    }

    public function testMultipleRowsStackVertically(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tr><td style="padding:0">um</td></tr><tr><td style="padding:0">dois</td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $table = $prepared->layoutRoot->children[0];
        self::assertCount(2, $table->children);
        [$firstRow, $secondRow] = $table->children;
        self::assertSame(0.0, $firstRow->box->content->y);
        self::assertGreaterThan(0.0, $secondRow->box->content->y);
        self::assertEqualsWithDelta($firstRow->box->content->y + $firstRow->box->content->height, $secondRow->box->content->y, 0.01);
    }

    public function testColumnWidthIsProportionalToEachColumnsNaturalContentWidth(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table style="width:400px"><tr><td style="padding:0">Local: Rio de Janeiro</td><td style="padding:0">Data: 20/08/2026</td></tr></table>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        [$first, $second] = $row->children;

        self::assertEqualsWithDelta(400.0, $first->box->content->width + $second->box->content->width, 0.5);
        self::assertGreaterThan($second->box->content->width, $first->box->content->width);
    }

    public function testRowHeightIsTheTallestCellInThatRow(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tr><td style="padding:0;height:10px">a</td><td style="padding:0;height:40px">b</td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        self::assertSame(40.0, $row->box->content->height);
    }

    public function testRowsWrappedInTbodyAreStillCollected(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tbody><tr><td>a</td></tr></tbody></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $table = $prepared->layoutRoot->children[0];
        self::assertCount(1, $table->children);
        self::assertSame('tr', $table->children[0]->source->node->tagName);
    }

    public function testWideColumnsScaleDownProportionallyInsteadOfOverflowingTheTable(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table style="width:100px;font-size:16px">'
                . '<tr><td>uma coluna com bastante texto que não caberia</td>'
                . '<td>outra coluna também bem longa de conteúdo</td></tr>'
                . '</table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        [$first, $second] = $row->children;
        // Border boxes, not content boxes: the columns still fill the table exactly, but each
        // cell's content is inset by the UA default cell padding.
        self::assertEqualsWithDelta(100.0, $first->box->borderBox()->width + $second->box->borderBox()->width, 0.5);
    }

    public function testRealWorldLocalizacaoDataTablePatternRendersSideBySide(): void
    {
        // Mirrors the pattern found in the real-world corpus that motivated this fix: a
        // two-cell header row (place/date) that previously had its two cells' text
        // concatenated into one illegible run.
        $html = '<table border="0" width="100%"><tr width="100%">'
            . '<td width="50%"><p><b>Local: </b>Rio de Janeiro</p></td>'
            . '<td width="50%"><p><b>Data: </b>20/08/2026</p></td>'
            . '</tr></table>';

        $prepared = Pagyra::prepareHtmlRender(['html' => $html, 'viewportWidth' => 400, 'viewportHeight' => 100]);
        $row = $prepared->layoutRoot->children[0]->children[0];

        self::assertCount(2, $row->children);
        self::assertSame($row->children[0]->box->content->y, $row->children[1]->box->content->y);
        self::assertStringContainsString('Local', $row->children[0]->children[0]->lineBoxes[0]->text);
        self::assertStringContainsString('Data', $row->children[1]->children[0]->lineBoxes[0]->text);
    }
}
