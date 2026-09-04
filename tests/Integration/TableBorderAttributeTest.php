<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TableBorderAttributeTest extends TestCase
{
    public function testBorderAttributeGivesTheTableAndEveryCellABorder(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table border="1"><tr><td>a</td><td>b</td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $table = $prepared->layoutRoot->children[0];
        self::assertGreaterThan(0.0, $table->box->border->top);

        [$a, $b] = $table->children[0]->children;
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            self::assertGreaterThan(0.0, $a->box->border->{$side}, "célula a, lado $side");
            self::assertGreaterThan(0.0, $b->box->border->{$side}, "célula b, lado $side");
        }
    }

    public function testTableWidthComesFromTheAttributeWhileCellsStayAtOnePixel(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table border="4"><tr><td>a</td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $table = $prepared->layoutRoot->children[0];
        self::assertSame(4.0, $table->box->border->top);
        self::assertSame(1.0, $table->children[0]->children[0]->box->border->top);
    }

    public function testAuthorCssBeatsThePresentationalHint(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table border="1"><tr><td style="border:none">a</td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $cell = $prepared->layoutRoot->children[0]->children[0]->children[0];
        self::assertSame(0.0, $cell->box->border->top);
    }

    public function testAbsentZeroOrInvalidBorderAttributeDrawsNothing(): void
    {
        foreach (['', ' border="0"', ' border="abc"'] as $attribute) {
            $prepared = Pagyra::prepareHtmlRender([
                'html' => "<table$attribute><tr><td>a</td></tr></table>",
                'viewportWidth' => 300,
                'viewportHeight' => 200,
            ]);

            $table = $prepared->layoutRoot->children[0];
            self::assertSame(0.0, $table->box->border->top, "atributo: '$attribute'");
            self::assertSame(0.0, $table->children[0]->children[0]->box->border->top, "atributo: '$attribute'");
        }
    }

    public function testCellOutsideAnyBorderedTableIsUnaffected(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table border="1"><tr><td>a</td></tr></table><table><tr><td>b</td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $comBorda = $prepared->layoutRoot->children[0]->children[0]->children[0];
        $semBorda = $prepared->layoutRoot->children[1]->children[0]->children[0];
        self::assertGreaterThan(0.0, $comBorda->box->border->top);
        self::assertSame(0.0, $semBorda->box->border->top);
    }
}
