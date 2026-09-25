<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use Pagyra\Paint\TransformPaintCommand;
use PHPUnit\Framework\TestCase;

final class CssTransformPaintTest extends TestCase
{
    public function testTransformWrapsBoxAndTextInBalancedScope(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div style="width:100px;height:40px;transform:rotate(90deg);transform-origin:left top">TEXT</div>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $commands = $prepared->displayList->pages[0]->commands;
        $open = [];
        $close = [];
        $text = null;
        foreach ($commands as $index => $command) {
            if ($command instanceof TransformPaintCommand) {
                if ($command->opens()) {
                    $open[] = $index;
                } else {
                    $close[] = $index;
                }
            }
            if ($command instanceof TextPaintCommand && trim($command->text) === 'TEXT') {
                $text = $index;
            }
        }

        self::assertCount(1, $open);
        self::assertCount(1, $close);
        self::assertNotNull($text);
        self::assertLessThan($text, $open[0]);
        self::assertGreaterThan($text, $close[0]);

        $matrix = $commands[$open[0]]->matrix;
        self::assertNotNull($matrix);
        self::assertEqualsWithDelta(1.0, $matrix->b, 1e-9);
        self::assertEqualsWithDelta(-1.0, $matrix->c, 1e-9);
        self::assertEqualsWithDelta(0.0, $commands[$open[0]]->originX, 1e-9);
        self::assertEqualsWithDelta(0.0, $commands[$open[0]]->originY, 1e-9);
    }

    public function testTransformDoesNotChangeLayoutGeometry(): void
    {
        $plain = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div style="width:100px;height:40px">TEXT</div><p style="margin:0">AFTER</p>',
        ]);
        $rotated = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div style="width:100px;height:40px;transform:rotate(30deg)">TEXT</div><p style="margin:0">AFTER</p>',
        ]);

        self::assertEqualsWithDelta(
            $plain->layoutRoot->children[1]->box->content->y,
            $rotated->layoutRoot->children[1]->box->content->y,
            1e-9,
        );
    }

    public function testPdfContainsCssToPdfRotatedMatrix(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'pagedBodyMargin' => 'zero',
            'margins' => 0.0,
            'html' => '<div style="width:100px;height:40px;transform:rotate(90deg);transform-origin:left top">TEXT</div>',
        ]);

        self::assertMatchesRegularExpression('/0(?:\.0+)? -1(?:\.0+)? 1(?:\.0+)? 0(?:\.0+)? 0 0 cm/', $pdf);
    }
}
