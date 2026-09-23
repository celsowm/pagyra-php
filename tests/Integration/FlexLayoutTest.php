<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\LayoutNode;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/** Flexbox layout: direction, wrapping, flexible lengths, alignment and ordering. */
final class FlexLayoutTest extends TestCase
{
    /** @return list<array{0:float,1:float,2:float,3:float}> border boxes of the container's items */
    private function items(string $container, string $children): array
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero', 'margins' => 0.0, 'pageWidth' => 400.0, 'viewportWidth' => 400.0,
            'html' => '<style>.i{height:20px;padding:0;margin:0}</style><div style="' . $container . '">' . $children . '</div>',
        ]);

        return array_map(static function (LayoutNode $node): array {
            $box = $node->box->borderBox();
            return [round($box->x, 3), round($box->y, 3), round($box->width, 3), round($box->height, 3)];
        }, $prepared->layoutRoot->children[0]->children);
    }

    public function testJustifyContentAndGap(): void
    {
        $three = '<div class="i" style="width:50px"></div><div class="i" style="width:50px"></div><div class="i" style="width:50px"></div>';

        self::assertSame([0.0, 50.0, 100.0], array_column($this->items('display:flex', $three), 0));
        self::assertSame([0.0, 175.0, 350.0], array_column($this->items('display:flex;justify-content:space-between', $three), 0));
        self::assertSame([125.0, 175.0, 225.0], array_column($this->items('display:flex;justify-content:center', $three), 0));
        self::assertSame([250.0, 300.0, 350.0], array_column($this->items('display:flex;justify-content:flex-end', $three), 0));
        self::assertSame([62.5, 175.0, 287.5], array_column($this->items('display:flex;justify-content:space-evenly', $three), 0));
        self::assertSame([0.0, 60.0, 120.0], array_column($this->items('display:flex;gap:10px', $three), 0));
    }

    public function testGrowAndShrink(): void
    {
        self::assertSame([100.0, 200.0, 100.0], array_column($this->items(
            'display:flex',
            '<div class="i" style="flex:1"></div><div class="i" style="flex:2"></div><div class="i" style="width:100px"></div>',
        ), 2));
        self::assertSame([200.0, 200.0], array_column($this->items(
            'display:flex',
            '<div class="i" style="width:300px"></div><div class="i" style="width:300px"></div>',
        ), 2));
        self::assertSame([300.0, 100.0], array_column($this->items(
            'display:flex',
            '<div class="i" style="width:300px;flex-shrink:0"></div><div class="i" style="width:300px"></div>',
        ), 2));
    }

    public function testItemsWithoutWidthTakeTheirContentWidth(): void
    {
        $items = $this->items('display:flex;justify-content:space-between', '<div>esquerda</div><div>direita</div>');

        self::assertSame(0.0, $items[0][0]);
        self::assertLessThan(100.0, $items[0][2]);
        self::assertEqualsWithDelta(400.0, $items[1][0] + $items[1][2], 0.001);
    }

    public function testWrapAndAlignment(): void
    {
        $wrapped = $this->items('display:flex;flex-wrap:wrap', str_repeat('<div class="i" style="width:150px"></div>', 3));
        self::assertSame([[0.0, 0.0], [150.0, 0.0], [0.0, 20.0]], array_map(static fn(array $b): array => [$b[0], $b[1]], $wrapped));

        $aligned = $this->items('display:flex;align-items:center;height:100px', '<div class="i" style="width:10px"></div><div style="width:10px;height:60px"></div>');
        self::assertSame([40.0, 20.0], array_column($aligned, 1));

        $stretched = $this->items('display:flex;height:80px', '<div style="width:10px"></div><div style="width:10px;align-self:flex-end;height:20px"></div>');
        self::assertSame([80.0, 20.0], array_column($stretched, 3));
        self::assertSame([0.0, 60.0], array_column($stretched, 1));
    }

    public function testColumnDirection(): void
    {
        $items = $this->items('display:flex;flex-direction:column;align-items:center', '<div class="i" style="width:100px"></div><div class="i" style="width:50px"></div>');

        self::assertSame([[150.0, 0.0], [175.0, 20.0]], array_map(static fn(array $b): array => [$b[0], $b[1]], $items));
    }

    public function testOrderReverseAndAutoMargins(): void
    {
        $two = '<div class="i" style="width:50px;order:2"></div><div class="i" style="width:60px;order:1"></div>';
        self::assertSame([[0.0, 60.0], [60.0, 50.0]], array_map(static fn(array $b): array => [$b[0], $b[2]], $this->items('display:flex', $two)));

        $reversed = $this->items('display:flex;flex-direction:row-reverse', '<div class="i" style="width:50px"></div><div class="i" style="width:60px"></div>');
        self::assertSame([350.0, 290.0], array_column($reversed, 0));

        $pushed = $this->items('display:flex', '<div class="i" style="width:50px"></div><div class="i" style="width:60px;margin-left:auto"></div>');
        self::assertSame([0.0, 340.0], array_column($pushed, 0));
    }

    public function testContainerHeightFollowsTheTallestItem(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<div style="display:flex"><div style="height:30px;width:10px"></div><div style="height:70px;width:10px"></div></div><p style="margin:0">depois</p>',
        ]);

        self::assertEqualsWithDelta(70.0, $prepared->layoutRoot->children[0]->box->content->height, 0.001);
        self::assertEqualsWithDelta(70.0, $prepared->layoutRoot->children[1]->box->content->y, 0.001);
    }
}
