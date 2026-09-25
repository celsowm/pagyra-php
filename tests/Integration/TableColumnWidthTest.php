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


    public function testMinContentProtectsAnUnbreakableColumnWhenPreferredWidthsOverflow(): void
    {
        [$unbreakable, $wrappable] = $this->cells(
            '<table style="width:200px;font-family:Courier;font-size:10px"><tr>'
            . '<td style="padding:0">ABCDEFGHIJ</td>'
            . '<td style="padding:0">' . str_repeat('aa ', 30) . '</td>'
            . '</tr></table>'
        );

        // Courier at 10px makes the ten-character token about 60px wide. A max-content-only
        // proportional shrink used to crush that column far below its unbreakable minimum.
        self::assertGreaterThan(55.0, $unbreakable->box->borderBox()->width);
        self::assertEqualsWithDelta(
            200.0,
            $unbreakable->box->borderBox()->width + $wrappable->box->borderBox()->width,
            0.5,
        );
    }

    public function testColElementsConstrainColumnProportions(): void
    {
        [$left, $right] = $this->cells(
            '<table style="width:600px"><colgroup>'
            . '<col style="width:100px"><col style="width:200px">'
            . '</colgroup><tr><td>a</td><td>b</td></tr></table>'
        );

        self::assertEqualsWithDelta(200.0, $left->box->borderBox()->width, 1.0);
        self::assertEqualsWithDelta(400.0, $right->box->borderBox()->width, 1.0);
    }

    public function testColSpanRepeatsTheColumnWidthHint(): void
    {
        [$first, $second, $third] = $this->cells(
            '<table style="width:400px"><colgroup>'
            . '<col span="2" style="width:100px"><col style="width:200px">'
            . '</colgroup><tr><td>a</td><td>b</td><td>c</td></tr></table>'
        );

        self::assertEqualsWithDelta(100.0, $first->box->borderBox()->width, 1.0);
        self::assertEqualsWithDelta(100.0, $second->box->borderBox()->width, 1.0);
        self::assertEqualsWithDelta(200.0, $third->box->borderBox()->width, 1.0);
    }

    public function testLegacyColWidthAttributeParticipatesInSizing(): void
    {
        [$left, $right] = $this->cells(
            '<table style="width:600px"><colgroup>'
            . '<col width="25%"><col width="75%">'
            . '</colgroup><tr><td>a</td><td>b</td></tr></table>'
        );

        self::assertEqualsWithDelta(150.0, $left->box->borderBox()->width, 1.0);
        self::assertEqualsWithDelta(450.0, $right->box->borderBox()->width, 1.0);
    }

    public function testColgroupWidthFallsBackToItsChildColumns(): void
    {
        [$left, $right] = $this->cells(
            '<table style="width:200px"><colgroup style="width:100px">'
            . '<col><col>'
            . '</colgroup><tr><td>a</td><td>b</td></tr></table>'
        );

        self::assertEqualsWithDelta(100.0, $left->box->borderBox()->width, 1.0);
        self::assertEqualsWithDelta(100.0, $right->box->borderBox()->width, 1.0);
    }

    public function testLegacyColgroupWidthAttributeFallsBackToChildColumns(): void
    {
        [$left, $right] = $this->cells(
            '<table style="width:200px"><colgroup width="100">'
            . '<col><col>'
            . '</colgroup><tr><td>a</td><td>b</td></tr></table>'
        );

        self::assertEqualsWithDelta(100.0, $left->box->borderBox()->width, 1.0);
        self::assertEqualsWithDelta(100.0, $right->box->borderBox()->width, 1.0);
    }

    public function testIntrinsicSizingWalksBlockDescendantsInsideCells(): void
    {
        [$short, $long] = $this->cells(
            '<table style="width:300px;font-family:Courier;font-size:10px"><tr>'
            . '<td style="padding:0"><div>aa</div></td>'
            . '<td style="padding:0"><div>ABCDEFGHIJKLMNO</div></td>'
            . '</tr></table>'
        );

        // InlineTextFormatter intentionally does not consume block children. The recursive
        // intrinsic-size resolver must still see them, matching pagyra-js's cell.walk() pass.
        self::assertGreaterThan($short->box->borderBox()->width, $long->box->borderBox()->width);
    }
}
