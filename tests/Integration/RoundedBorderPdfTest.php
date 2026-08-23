<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Paint\BorderPaintCommand;
use Pagyra\Paint\RoundedBorderPaintCommand;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class RoundedBorderPdfTest extends TestCase
{
    public function testUniformSolidRoundedBorderUsesEvenOddRing(): void
    {
        $html = '<style>@page{size:200px 100px;margin:0}</style>'
            . '<div style="margin:0;width:100px;height:40px;border-width:4px;border-style:solid;border-color:rgba(255,0,0,.5);border-radius:20px"></div>';
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        $commands = $prepared->displayList?->pages[0]->commands ?? [];
        $rounded = array_values(array_filter($commands, static fn (object $command): bool => $command instanceof RoundedBorderPaintCommand));
        $flat = array_values(array_filter($commands, static fn (object $command): bool => $command instanceof BorderPaintCommand));
        self::assertCount(1, $rounded);
        self::assertCount(0, $flat);
        self::assertSame(4.0, $rounded[0]->borderWidth);
        self::assertSame(20.0, $rounded[0]->outerRadius->topLeft->x);
        self::assertSame(16.0, $rounded[0]->innerRadius->topLeft->x);
        self::assertSame(16.0, $rounded[0]->innerRadius->topLeft->y);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => $html,
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);
        self::assertStringContainsString("f*\n", $pdf);
        self::assertGreaterThanOrEqual(8, substr_count($pdf, " c\n"));
        self::assertStringContainsString('/Type /ExtGState /ca 0.5 /CA 0.5', $pdf);
        self::assertStringContainsString('/GS1 gs', $pdf);
    }

    public function testAsymmetricBorderKeepsPerSideCommands(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="margin:0;width:100px;height:40px;border-style:solid;border-width:2px 3px 4px 5px;border-color:red;border-radius:20px"></div>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);
        $commands = $prepared->displayList?->pages[0]->commands ?? [];
        self::assertCount(0, array_filter($commands, static fn (object $command): bool => $command instanceof RoundedBorderPaintCommand));
        self::assertCount(4, array_filter($commands, static fn (object $command): bool => $command instanceof BorderPaintCommand));
    }
}
