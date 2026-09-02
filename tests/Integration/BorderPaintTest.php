<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BorderPaintCommand;
use PHPUnit\Framework\TestCase;

final class BorderPaintTest extends TestCase
{
    public function testSolidPerSideBordersBecomePhysicalPaintCommandsAndPdfFills(): void
    {
        $html = '<style>@page{size:200px 120px;margin:10px}</style>'
            . '<div style="margin:0;width:40px;height:20px;color:#112233;'
            . 'border-width:2px 3px 4px 5px;border-style:solid;'
            . 'border-top-color:red;border-right-color:green;'
            . 'border-bottom-color:blue;border-left-color:currentColor"></div>';

        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 200,
            'viewportHeight' => 120,
        ]);

        $borders = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $command): bool => $command instanceof BorderPaintCommand,
        ));

        self::assertCount(4, $borders);
        self::assertSame(['top', 'bottom', 'left', 'right'], array_map(static fn (BorderPaintCommand $b): string => $b->side, $borders));

        self::assertSame(2.0, $borders[0]->height);
        self::assertSame(4.0, $borders[1]->height);
        self::assertSame(5.0, $borders[2]->width);
        self::assertSame(3.0, $borders[3]->width);

        self::assertSame(255.0, $borders[0]->color->r);
        self::assertSame(128.0, $borders[3]->color->g);
        self::assertSame(255.0, $borders[1]->color->b);
        self::assertSame(17.0, $borders[2]->color->r);
        self::assertSame(34.0, $borders[2]->color->g);
        self::assertSame(51.0, $borders[2]->color->b);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => $html,
            'viewportWidth' => 200,
            'viewportHeight' => 120,
        ]);

        self::assertStringContainsString("1 0 0 rg\n", $pdf);
        self::assertStringContainsString("0 0.501961 0 rg\n", $pdf);
        self::assertStringContainsString("0 0 1 rg\n", $pdf);
        self::assertStringContainsString("0.066667 0.133333 0.2 rg\n", $pdf);
    }

    public function testDashedBorderIsNotPaintedAsOneSolidRun(): void
    {
        // Written when `dashed` was unpainted, asserting an empty display list was better than a
        // solid rectangle pretending to be a dash pattern. Dashed is painted now, so what still
        // has to hold is the same thing: each side comes out as several separated segments, never
        // as a single run spanning the whole edge.
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:40px;height:20px;border:0;border-width:3px;border-style:dashed;border-color:red"></div>',
            'viewportWidth' => 200,
            'viewportHeight' => 120,
        ]);

        $top = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $command): bool => $command instanceof BorderPaintCommand && $command->side === 'top',
        ));

        self::assertGreaterThan(1, count($top), 'a borda tracejada saiu como um traco unico');
        foreach ($top as $segment) {
            self::assertLessThan(40.0, $segment->width, 'um segmento cobre a aresta inteira');
        }

        $gaps = [];
        for ($i = 1, $count = count($top); $i < $count; $i++) {
            $gaps[] = $top[$i]->x - ($top[$i - 1]->x + $top[$i - 1]->width);
        }
        self::assertNotSame([], $gaps);
        foreach ($gaps as $gap) self::assertGreaterThan(0.0, $gap, 'segmentos encostados, sem intervalo');
    }
}
