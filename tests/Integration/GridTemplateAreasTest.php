<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * `grid-template-areas` (CSS Grid 1 §8.3) names a rectangle of cells that a child's
 * `grid-area: <name>` places itself at. `grid-area` already resolved the four-number shorthand
 * (`row-start / col-start / row-end / col-end`), so a bare name fell through the same parser,
 * which treats anything that is not `span N` or a positive integer as `auto` — an item named
 * `grid-area: header` landed wherever auto-placement put it instead of the area layouts like
 * this actually name it for.
 */
final class GridTemplateAreasTest extends TestCase
{
    /** @return list<array{0:float,1:float,2:float,3:float}> the items' border boxes in reading order */
    private function items(string $container, string $children): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero', 'margins' => 0.0, 'pageWidth' => 400.0, 'viewportWidth' => 400.0,
            'html' => '<style>.i{height:20px}</style><div style="display:grid;' . $container . '">' . $children . '</div>',
        ]);

        return array_map(static function (LayoutNode $node): array {
            $box = $node->box->borderBox();
            return [round($box->x, 3), round($box->y, 3), round($box->width, 3), round($box->height, 3)];
        }, $prepared->layoutRoot->children[0]->children);
    }

    public function testItemsLandOnTheirNamedCellsRegardlessOfMarkupOrder(): void
    {
        $items = $this->items(
            'grid-template-columns:100px 200px;grid-template-areas:\'header header\' \'sidebar content\'',
            '<div class="i" style="grid-area:content"></div>'
            . '<div class="i" style="grid-area:header"></div>'
            . '<div class="i" style="grid-area:sidebar"></div>',
        );

        // Items come back in reading order (top-to-bottom, left-to-right), not markup order,
        // which is layoutGrid()'s own existing behaviour: header (row 0) sorts before sidebar
        // and content (row 1, sidebar left of content).
        [$header, $sidebar, $content] = $items;
        self::assertSame([0.0, 0.0, 300.0, 20.0], $header);
        self::assertSame([0.0, 20.0, 100.0, 20.0], $sidebar);
        self::assertSame([100.0, 20.0, 200.0, 20.0], $content);
    }

    public function testANameSpanningSeveralRowsAndColumnsGetsTheirFullBoundingBox(): void
    {
        $items = $this->items(
            'grid-template-columns:100px 100px 100px;grid-template-areas:\'a a b\' \'a a b\' \'c c b\'',
            '<div class="i" style="grid-area:a"></div>'
            . '<div class="i" style="grid-area:b"></div>'
            . '<div class="i" style="grid-area:c"></div>',
        );

        [$a, $b, $c] = $items;
        // 'a' spans the top-left 2x2 block: both of its columns (100+100).
        self::assertEqualsWithDelta(200.0, $a[2], 0.01);
        // 'b' spans all three rows in the rightmost (third) column only.
        self::assertEqualsWithDelta(200.0, $b[0], 0.01);
        self::assertEqualsWithDelta(100.0, $b[2], 0.01);
        // 'c' sits in the third row, spanning the same two left columns as 'a'.
        self::assertEqualsWithDelta(200.0, $c[2], 0.01);
        self::assertGreaterThan($a[1], $c[1]);
    }

    public function testADotIsAnEmptyCellNotAName(): void
    {
        $items = $this->items(
            'grid-template-columns:100px 100px;grid-template-areas:\'a .\' \'. b\'',
            '<div class="i" style="grid-area:a"></div><div class="i" style="grid-area:b"></div>',
        );

        self::assertSame([0.0, 0.0, 100.0, 20.0], $items[0]);
        self::assertSame([100.0, 20.0, 100.0, 20.0], $items[1]);
    }

    public function testTheNumericShorthandStillWorksAlongsideNamedAreas(): void
    {
        $items = $this->items(
            'grid-template-columns:100px 100px 100px;grid-template-areas:\'a a a\'',
            '<div class="i" style="grid-area:2/2/3/3"></div>',
        );

        // Row 0, which `grid-template-areas` declares but nothing actually occupies here, stays
        // zero height, so the numerically placed item's row 1 starts right after it at y:0.
        self::assertSame([100.0, 0.0, 100.0, 20.0], $items[0]);
    }
}
