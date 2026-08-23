<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class RoundedBackgroundPdfTest extends TestCase
{
    public function testRoundedBackgroundCarriesNormalizedRadiusAndUsesBezierPath(): void
    {
        $html = '<style>@page{size:200px 100px;margin:0}div{margin:0;width:100px;height:40px;background:red;border-radius:50%}</style><div></div>';
        $prepared = Pagyra::prepareHtmlRender([
            'html' => $html,
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        $boxes = array_values(array_filter(
            $prepared->displayList?->pages[0]->commands ?? [],
            static fn (object $command): bool => $command instanceof BoxPaintCommand && $command->backgroundColor !== null,
        ));
        self::assertNotEmpty($boxes);
        $box = $boxes[0];
        self::assertSame(50.0, $box->borderRadius->topLeft->x);
        self::assertSame(20.0, $box->borderRadius->topLeft->y);
        self::assertSame(50.0, $box->borderRadius->bottomRight->x);
        self::assertSame(20.0, $box->borderRadius->bottomRight->y);

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => $html,
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);
        self::assertStringContainsString(" c\n", $pdf);
        self::assertStringContainsString("h\nf\n", $pdf);
    }

    public function testSquareBackgroundKeepsRectangleFastPath(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>@page{size:200px 100px;margin:0}</style><div style="margin:0;width:100px;height:40px;background:red"></div>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString(" re f\n", $pdf);
    }
}
