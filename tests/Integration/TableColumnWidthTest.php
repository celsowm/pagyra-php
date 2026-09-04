<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TableColumnWidthTest extends TestCase
{
    /** @return list<\Pagyra\Layout\LayoutNode> */
    private function cells(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 800,
            'viewportHeight' => 600,
        ]);

        return $prepared->layoutRoot->children[0]->children[0]->children;
    }

    public function testWidthAttributeSetsTheColumnProportions(): void
    {
        // The requisição grid of the real corpus: the table is width:100% and the proportion lives
        // entirely in the cells' width attributes, 378 against 227, i.e. 62.5% / 37.5%.
        [$left, $right] = $this->cells(
            '<table style="width:600px"><tr>'
            . '<td width="378">esquerda</td><td width="227">direita</td>'
            . '</tr></table>'
        );

        $leftWidth = $left->box->borderBox()->width;
        $rightWidth = $right->box->borderBox()->width;

        self::assertEqualsWithDelta(600.0, $leftWidth + $rightWidth, 0.5);
        self::assertEqualsWithDelta(378.0 / 605.0, $leftWidth / ($leftWidth + $rightWidth), 0.01);
    }

    public function testCellsFillTheirColumnLeavingNoGapBetweenThem(): void
    {
        // A declared width states the column's preferred width, not the cell's final width: once
        // the leftover space is distributed the cell has to fill the column, or the grid is drawn
        // with an unpainted strip between the columns.
        [$left, $right] = $this->cells(
            '<table style="width:600px"><tr>'
            . '<td width="100">esquerda</td><td width="100">direita</td>'
            . '</tr></table>'
        );

        self::assertEqualsWithDelta($left->box->borderBox()->right(), $right->box->borderBox()->x, 0.5);
        self::assertEqualsWithDelta(600.0, $left->box->borderBox()->width + $right->box->borderBox()->width, 0.5);
    }

    public function testPercentageWidthAttributeIsHonoured(): void
    {
        [$left, $right] = $this->cells(
            '<table style="width:600px"><tr>'
            . '<td width="25%">esquerda</td><td width="75%">direita</td>'
            . '</tr></table>'
        );

        self::assertEqualsWithDelta(150.0, $left->box->borderBox()->width, 1.0);
        self::assertEqualsWithDelta(450.0, $right->box->borderBox()->width, 1.0);
    }

    public function testColumnsWithoutADeclaredWidthStillComeFromTheirContent(): void
    {
        [$short, $long] = $this->cells(
            '<table style="width:600px"><tr>'
            . '<td style="padding:0">a</td>'
            . '<td style="padding:0">um conteúdo bem mais longo nesta coluna</td>'
            . '</tr></table>'
        );

        self::assertGreaterThan($short->box->borderBox()->width, $long->box->borderBox()->width);
    }
}
