<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * `position: absolute`/`fixed` (CSS 2.1 §10.1, §9.6) were parsed but never moved a box: it stayed
 * wherever the normal flow put it. Like `position: relative`, this port does not pull the box out
 * of the flow it was laid out in (pagyra-js does not either), so a sibling after it is unaffected.
 */
final class AbsolutePositioningTest extends TestCase
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

    public function testAbsoluteWithNoPositionedAncestorUsesThePage(): void
    {
        $moved = $this->texts('<div style="padding:60px;margin:0"><div style="margin:0"><span style="position:absolute;left:5px;top:5px">x</span></div></div>');

        self::assertEqualsWithDelta(5.0, $moved['x']->x, 0.5);
    }

    public function testAbsoluteUsesTheNearestPositionedAncestorAsContainingBlock(): void
    {
        $html = fn(string $position): string => '<div style="padding-left:60px;margin:0"><div style="position:relative;margin:0"><span style="position:' . $position . ';left:0;top:0">x</span></div></div>';

        self::assertEqualsWithDelta(60.0, $this->texts($html('absolute'))['x']->x, 0.5);
    }

    public function testFixedIgnoresThePositionedAncestorAndUsesThePage(): void
    {
        $html = fn(string $position): string => '<div style="padding-left:60px;margin:0"><div style="position:relative;margin:0"><span style="position:' . $position . ';left:0;top:0">x</span></div></div>';

        self::assertEqualsWithDelta(0.0, $this->texts($html('fixed'))['x']->x, 0.5);
    }

    public function testRightAndBottomMoveFromTheOppositeEdge(): void
    {
        $render = Pagyra::prepareHtmlRender(['html' => '<div style="position:absolute;right:10px;top:0;width:50px;margin:0">x</div>', 'pagedBodyMargin' => 'zero', 'margins' => 0.0]);
        $pageWidth = $render->displayList->pages[0]->width;
        $found = [];
        foreach ($render->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) $found['x'] = $command;
        }

        self::assertEqualsWithDelta($pageWidth - 50.0 - 10.0, $found['x']->x, 0.5);
    }

    public function testBothInsetsAutoPinsToTheContainingBlockOrigin(): void
    {
        $moved = $this->texts('<div style="position:relative;padding:40px;margin:0"><span style="position:absolute">x</span></div>');

        self::assertEqualsWithDelta(40.0, $moved['x']->x, 0.5);
    }

    public function testStaticFlowSiblingsAreUnaffected(): void
    {
        $plain = $this->texts('<p style="margin:0">a</p><div style="margin:0"><p style="margin:0">b</p></div><p style="margin:0">c</p>');
        $moved = $this->texts('<p style="margin:0">a</p><div style="position:absolute;left:300px;top:300px;margin:0"><p style="margin:0">b</p></div><p style="margin:0">c</p>');

        self::assertEqualsWithDelta($plain['c']->y, $moved['c']->y, 0.5);
    }
}
