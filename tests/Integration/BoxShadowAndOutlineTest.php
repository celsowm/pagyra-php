<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\BoxPaintCommand;
use PHPUnit\Framework\TestCase;

/** Outer `box-shadow` layers under the background, and `outline` outside the border box. */
final class BoxShadowAndOutlineTest extends TestCase
{
    /** @return list<BoxPaintCommand> */
    private function decorative(string $html): array
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => $html, 'pagedBodyMargin' => 'zero', 'margins' => 0.0]);

        return array_values(array_filter(
            $prepared->displayList->pages[0]->commands,
            static fn(object $c): bool => $c instanceof BoxPaintCommand && $c->decorative,
        ));
    }

    public function testHardShadowIsTheBorderBoxMovedAndSpreadUnderTheBackground(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero', 'margins' => 0.0,
            'html' => '<div style="width:100px;height:50px;background:#fff;box-shadow:4px 6px 0 2px rgba(0,0,0,.5)"></div>',
        ]);
        $commands = $prepared->displayList->pages[0]->commands;
        $shadow = $commands[0];

        self::assertInstanceOf(BoxPaintCommand::class, $shadow);
        self::assertTrue($shadow->decorative);
        self::assertEqualsWithDelta([2.0, 4.0, 104.0, 54.0], [$shadow->x, $shadow->y, $shadow->width, $shadow->height], 1e-6);
        self::assertEqualsWithDelta(0.5, $shadow->backgroundColor->a, 1e-6);
        self::assertFalse($commands[1]->decorative);
    }

    public function testBlurIsSpreadOverLayersAndInsetIsSkipped(): void
    {
        $layers = $this->decorative('<div style="width:100px;height:50px;box-shadow:0 0 8px #000, inset 0 0 4px red"></div>');

        self::assertCount(4, $layers);
        self::assertEqualsWithDelta(0.25, $layers[0]->backgroundColor->a, 1e-6);
        self::assertLessThan($layers[3]->width, $layers[0]->width);
    }

    public function testOutlineSurroundsTheBorderBoxAtItsOffset(): void
    {
        $sides = $this->decorative('<div style="width:100px;height:50px;outline:2px dashed #ff0000;outline-offset:3px"></div>');

        self::assertCount(4, $sides);
        self::assertEqualsWithDelta([-5.0, -5.0, 110.0, 2.0], [$sides[0]->x, $sides[0]->y, $sides[0]->width, $sides[0]->height], 1e-6);
        self::assertSame(255, (int) round($sides[0]->backgroundColor->r));
        self::assertSame([], $this->decorative('<div style="outline:none;width:10px;height:10px"></div>'));
    }
}
