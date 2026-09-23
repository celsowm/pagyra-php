<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/** CSS Grid: track sizing, placement, spans, gaps and alignment. */
final class GridLayoutTest extends TestCase
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

    public function testFixedFractionalAndGap(): void
    {
        $items = $this->items('grid-template-columns:100px 1fr 2fr;gap:10px', str_repeat('<div class="i"></div>', 4));

        self::assertSame([[0.0, 0.0, 100.0, 20.0], [110.0, 0.0, 93.333, 20.0], [213.333, 0.0, 186.667, 20.0], [0.0, 30.0, 100.0, 20.0]], $items);
    }

    public function testRepeatMinmaxAndAutoFill(): void
    {
        self::assertSame([0.0, 100.0, 200.0, 300.0], array_column($this->items('grid-template-columns:repeat(4, 1fr)', str_repeat('<div class="i"></div>', 4)), 0));
        self::assertSame([0.0, 130.0, 260.0], array_column($this->items('grid-template-columns:repeat(auto-fill, 120px);column-gap:10px', str_repeat('<div class="i"></div>', 3)), 0));
        self::assertSame([200.0, 200.0], array_column($this->items('grid-template-columns:minmax(150px, 1fr) 1fr', '<div class="i"></div><div class="i"></div>'), 2));
        self::assertSame([250.0, 150.0], array_column($this->items('grid-template-columns:minmax(250px, 1fr) 1fr', '<div class="i"></div><div class="i"></div>'), 2));
    }

    public function testExplicitPlacementAndSpans(): void
    {
        $items = $this->items(
            'grid-template-columns:repeat(3, 100px)',
            '<div class="i" style="grid-column:2 / span 2"></div><div class="i" style="grid-row:2;grid-column:1"></div><div class="i"></div>',
        );

        self::assertSame([[100.0, 0.0, 200.0, 20.0], [0.0, 20.0, 100.0, 20.0], [100.0, 20.0, 100.0, 20.0]], $items);
    }

    public function testRowSpanAndStretch(): void
    {
        $items = $this->items(
            'grid-template-columns:100px 100px',
            '<div style="grid-row:span 2"></div><div class="i"></div><div style="height:30px"></div>',
        );

        self::assertSame([0.0, 0.0, 100.0, 50.0], $items[0]);
        self::assertSame([100.0, 20.0, 100.0, 30.0], $items[2]);
    }

    public function testAutoColumnsTakeTheirContentAndAlignment(): void
    {
        $items = $this->items('grid-template-columns:auto 1fr;align-items:center;grid-auto-rows:60px', '<div style="width:80px" class="i"></div><div class="i" style="justify-self:end;width:50px"></div>');

        self::assertSame([[0.0, 20.0, 80.0, 20.0], [350.0, 20.0, 50.0, 20.0]], $items);
    }
}
