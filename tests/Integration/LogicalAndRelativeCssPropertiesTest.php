<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Css\DeclarationParser;
use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/** Logical properties, relative font weights, `text-align-last` and `aspect-ratio`. */
final class LogicalAndRelativeCssPropertiesTest extends TestCase
{
    public function testLogicalPropertiesMapToPhysicalOnes(): void
    {
        self::assertSame([
            'border-bottom-color' => 'blue',
            'border-left-color' => 'red',
            'border-left-style' => 'solid',
            'border-left-width' => '3px',
            'bottom' => '1px',
            'left' => '2px',
            'margin-left' => 'auto',
            'margin-right' => 'auto',
            'padding-bottom' => '2em',
            'padding-top' => '1em',
            'right' => '2px',
            'top' => '1px',
            'width' => '50%',
        ], (new DeclarationParser())->parse(
            'margin-inline: auto; padding-block: 1em 2em; border-inline-start: 3px solid red; inline-size: 50%; inset: 1px 2px; border-block-end-color: blue',
        ));
    }

    public function testLogicalMarginsCentreABlock(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero', 'margins' => 0.0, 'pageWidth' => 400.0, 'viewportWidth' => 400.0,
            'html' => '<div style="inline-size:100px;margin-inline:auto;background:#ccc;block-size:10px"></div>',
        ]);
        $box = array_values(array_filter($prepared->displayList->pages[0]->commands, static fn($c) => $c instanceof BoxPaintCommand && $c->backgroundColor !== null))[0];

        self::assertEqualsWithDelta(150.0, $box->x, 0.001);
        self::assertEqualsWithDelta(10.0, $box->height, 0.001);
    }

    public function testBolderAndLighterAreRelativeToTheParent(): void
    {
        $weights = [];
        $prepared = Pagyra::prepareHtmlRender(['html' => '<p>n <span style="font-weight:bolder">b <span style="font-weight:bolder">bb</span> <span style="font-weight:lighter">l</span></span></p>']);
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand && trim($command->text) !== '') $weights[trim($command->text)] = $command->fontWeight;
        }

        self::assertSame(['n' => 400, 'b' => 700, 'bb' => 900, 'l' => 400], $weights);
    }

    public function testTextAlignLastAlignsTheLastLine(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero', 'margins' => 0.0,
            'html' => '<p style="width:200px;margin:0;text-align:justify;text-align-last:right">aaa bbb ccc ddd eee fff ggg hhh iii jjj kkk</p>',
        ]);
        $lines = $prepared->layoutRoot->children[0]->lineBoxes;
        $last = $lines[array_key_last($lines)];

        self::assertGreaterThan(1, count($lines));
        self::assertEqualsWithDelta(200.0, $last->x + $last->width, 0.01);
    }

    public function testAspectRatioGivesAnAutoHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="width:100px;aspect-ratio:2/1;background:#ccc"></div><div style="width:90px;aspect-ratio:3;background:#ccc"></div>',
        ]);
        $heights = [];
        foreach ($prepared->displayList->pages[0]->commands as $command) {
            if ($command instanceof BoxPaintCommand && $command->backgroundColor !== null) $heights[] = $command->height;
        }

        self::assertEqualsWithDelta([50.0, 30.0], $heights, 0.001);
    }
}
