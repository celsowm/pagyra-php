<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/** `position: relative` moves a box by its offsets without moving anything around it. */
final class RelativePositioningTest extends TestCase
{
    /** @return array<string,TextPaintCommand> */
    private function texts(string $html): array
    {
        $found = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html, 'pagedBodyMargin' => 'zero', 'margins' => 0.0])->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand && trim($command->text) !== '') $found[trim($command->text)] = $command;
        }

        return $found;
    }

    public function testBlockMovesWithItsContentAndLeavesTheFlowAlone(): void
    {
        $plain = $this->texts('<p style="margin:0">a</p><div><p style="margin:0">b</p></div><p style="margin:0">c</p>');
        $moved = $this->texts('<p style="margin:0">a</p><div style="position:relative;left:40px;top:15px"><p style="margin:0">b</p></div><p style="margin:0">c</p>');

        self::assertEqualsWithDelta($plain['b']->x + 40.0, $moved['b']->x, 0.001);
        self::assertEqualsWithDelta($plain['b']->y + 15.0, $moved['b']->y, 0.001);
        self::assertEqualsWithDelta($plain['c']->y, $moved['c']->y, 0.001);
    }

    public function testRightAndBottomMoveTheOtherWay(): void
    {
        $plain = $this->texts('<div>x</div>');
        $moved = $this->texts('<div style="position:relative;right:10px;bottom:5px">x</div>');

        self::assertEqualsWithDelta($plain['x']->x - 10.0, $moved['x']->x, 0.001);
        self::assertEqualsWithDelta($plain['x']->y - 5.0, $moved['x']->y, 0.001);
    }

    public function testInlineElementMovesOnlyItsOwnText(): void
    {
        $plain = $this->texts('<p>a <span>meio</span> b</p>');
        $moved = $this->texts('<p>a <span style="position:relative;top:10px;left:20px">meio</span> b</p>');

        self::assertEqualsWithDelta($plain['meio']->x + 20.0, $moved['meio']->x, 0.001);
        self::assertEqualsWithDelta($plain['meio']->y + 10.0, $moved['meio']->y, 0.001);
        self::assertEqualsWithDelta($plain['b']->x, $moved['b']->x, 0.001);
        self::assertEqualsWithDelta($plain['a']->y, $moved['a']->y, 0.001);
    }

    public function testStaticAndOffsetlessBoxesStay(): void
    {
        $plain = $this->texts('<div>x</div>');

        self::assertEqualsWithDelta($plain['x']->x, $this->texts('<div style="left:30px">x</div>')['x']->x, 0.001);
        self::assertEqualsWithDelta($plain['x']->y, $this->texts('<div style="position:relative">x</div>')['x']->y, 0.001);
    }
}
