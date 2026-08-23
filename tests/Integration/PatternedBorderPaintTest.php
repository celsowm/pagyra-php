<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BorderPaintCommand;
use PHPUnit\Framework\TestCase;

final class PatternedBorderPaintTest extends TestCase
{
    public function testUniformDashedBorderUsesThreeWidthDashAndGap(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:120px 80px;margin:0}div{box-sizing:border-box;width:50px;height:30px;border:2px dashed #000;margin:0}</style><div></div>',
            'pageWidth' => 120.0,
            'pageHeight' => 80.0,
            'viewportWidth' => 120.0,
            'viewportHeight' => 80.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        $borders = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $command): bool => $command instanceof BorderPaintCommand,
        ));

        $top = array_values(array_filter($borders, static fn (BorderPaintCommand $command): bool => $command->side === 'top'));
        self::assertGreaterThanOrEqual(4, count($top));
        self::assertSame(6.0, $top[0]->width);
        self::assertSame(2.0, $top[0]->height);
        self::assertSame(1.0, $top[0]->y);
        self::assertSame(7.0, $top[1]->x - $top[0]->x);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>@page{size:120px 80px;margin:0}div{box-sizing:border-box;width:50px;height:30px;border:2px dashed #000;margin:0}</style><div></div>',
            'pageWidth' => 120.0,
            'pageHeight' => 80.0,
            'viewportWidth' => 120.0,
            'viewportHeight' => 80.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);
        self::assertGreaterThanOrEqual(4, substr_count($pdf, ' re f'));
    }

    public function testMixedStylesSwitchAllVisibleSidesToStrokeGeometry(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:140px 90px;margin:0}div{box-sizing:border-box;width:60px;height:40px;margin:0;'
                . 'border-width:4px;border-color:red green blue black;'
                . 'border-style:solid dotted dashed none}</style><div></div>',
            'pageWidth' => 140.0,
            'pageHeight' => 90.0,
            'viewportWidth' => 140.0,
            'viewportHeight' => 90.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        $borders = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $command): bool => $command instanceof BorderPaintCommand,
        ));

        $top = array_values(array_filter($borders, static fn (BorderPaintCommand $command): bool => $command->side === 'top'));
        $right = array_values(array_filter($borders, static fn (BorderPaintCommand $command): bool => $command->side === 'right'));
        $bottom = array_values(array_filter($borders, static fn (BorderPaintCommand $command): bool => $command->side === 'bottom'));
        $left = array_values(array_filter($borders, static fn (BorderPaintCommand $command): bool => $command->side === 'left'));

        self::assertCount(1, $top);
        self::assertGreaterThan(1, count($right));
        self::assertGreaterThan(1, count($bottom));
        self::assertCount(0, $left);
        self::assertSame(4.0, $top[0]->height);
        self::assertSame(4.0, $right[0]->width);
        self::assertSame(4.0, $right[0]->height);
        self::assertSame(12.0, $bottom[0]->width);
    }

    public function testFragmentedDashedBorderOnlyDrawsTopAndBottomOnOuterFragments(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<style>@page{size:120px 60px;margin:0}div{box-sizing:border-box;width:50px;height:130px;border:2px dashed #000;margin:0}</style><div></div>',
            'pageWidth' => 120.0,
            'pageHeight' => 60.0,
            'viewportWidth' => 120.0,
            'viewportHeight' => 60.0,
            'margins' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        ]);

        self::assertSame(3, $prepared->pagination?->pageCount);
        foreach ([0, 1, 2] as $pageIndex) {
            $borders = array_values(array_filter(
                $prepared->displayList?->pages[$pageIndex]->commands ?? [],
                static fn (object $command): bool => $command instanceof BorderPaintCommand,
            ));
            $top = array_filter($borders, static fn (BorderPaintCommand $command): bool => $command->side === 'top');
            $bottom = array_filter($borders, static fn (BorderPaintCommand $command): bool => $command->side === 'bottom');
            self::assertSame($pageIndex === 0, count($top) > 0);
            self::assertSame($pageIndex === 2, count($bottom) > 0);
        }
    }
}
