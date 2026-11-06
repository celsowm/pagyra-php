<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TableBorderSpacingTest extends TestCase
{
    private const DELTA = 0.001;

    public function testDefaultSpacingSeparatesEveryCellByTwoPixels(): void
    {
        $table = $this->table('<table border="1" style="width:200px"><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>');
        [$a, $b] = $table->children[0]->children;
        [$c] = $table->children[1]->children;

        self::assertEqualsWithDelta($table->box->content->x + 2.0, $a->box->borderBox()->x, self::DELTA);
        self::assertEqualsWithDelta($a->box->borderBox()->right() + 2.0, $b->box->borderBox()->x, self::DELTA);
        self::assertEqualsWithDelta($table->box->content->right() - 2.0, $b->box->borderBox()->right(), self::DELTA);
        self::assertEqualsWithDelta($table->box->content->y + 2.0, $a->box->borderBox()->y, self::DELTA);
        self::assertEqualsWithDelta($a->box->borderBox()->bottom() + 2.0, $c->box->borderBox()->y, self::DELTA);
        self::assertEqualsWithDelta($table->box->content->bottom() - 2.0, $c->box->borderBox()->bottom(), self::DELTA);
    }

    public function testCellspacingZeroMakesTheCellsTouch(): void
    {
        $table = $this->table('<table border="1" cellspacing="0" style="width:200px"><tr><td>a</td><td>b</td></tr></table>');
        [$a, $b] = $table->children[0]->children;

        self::assertEqualsWithDelta($table->box->content->x, $a->box->borderBox()->x, self::DELTA);
        self::assertEqualsWithDelta($a->box->borderBox()->right(), $b->box->borderBox()->x, self::DELTA);
        self::assertEqualsWithDelta($table->box->content->y, $a->box->borderBox()->y, self::DELTA);
    }

    public function testTwoValuesAreHorizontalThenVertical(): void
    {
        $table = $this->table('<table style="width:200px;border-spacing:4px 6px"><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>');
        [$a, $b] = $table->children[0]->children;
        [$c] = $table->children[1]->children;

        self::assertEqualsWithDelta($a->box->borderBox()->right() + 4.0, $b->box->borderBox()->x, self::DELTA);
        self::assertEqualsWithDelta($a->box->borderBox()->bottom() + 6.0, $c->box->borderBox()->y, self::DELTA);
        self::assertEqualsWithDelta(2 * 6.0 + 6.0, $table->box->content->height - $a->box->borderBox()->height - $c->box->borderBox()->height, self::DELTA);
    }

    public function testCollapsedBordersIgnoreTheSpacing(): void
    {
        $table = $this->table('<table border="1" cellspacing="5" style="width:200px;border-collapse:collapse"><tr><td>a</td><td>b</td></tr></table>');
        [$a, $b] = $table->children[0]->children;

        self::assertEqualsWithDelta($table->box->content->x, $a->box->borderBox()->x, self::DELTA);
        self::assertEqualsWithDelta($a->box->borderBox()->right(), $b->box->borderBox()->x, self::DELTA);
    }

    public function testSpanningCellsCoverTheSpacingTheySpan(): void
    {
        $table = $this->table(
            '<table cellspacing="3" style="width:200px">'
            . '<tr><td colspan="2" rowspan="2">a</td><td>b</td></tr>'
            . '<tr><td>c</td></tr>'
            . '<tr><td>d</td><td>e</td><td>f</td></tr>'
            . '</table>',
        );
        [$a] = $table->children[0]->children;
        [$c] = $table->children[1]->children;
        [, $e] = $table->children[2]->children;

        self::assertEqualsWithDelta($e->box->borderBox()->right(), $a->box->borderBox()->right(), self::DELTA);
        self::assertEqualsWithDelta($c->box->borderBox()->bottom(), $a->box->borderBox()->bottom(), self::DELTA);
    }

    private function table(string $html): LayoutNode
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 300,
            'viewportHeight' => 400,
        ]);

        return $prepared->layoutRoot->children[0];
    }
}
