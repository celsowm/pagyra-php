<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * Cell content is placed by `vertical-align` (CSS 2.1 17.5.3), `middle` by default, and the text
 * inside a cell is no longer re-aligned line by line by the cell's own `vertical-align`.
 */
final class TableCellVerticalAlignTest extends TestCase
{
    /** @return array<string,TextPaintCommand> */
    private function texts(string $html): array
    {
        $found = [];
        foreach (Pagyra::prepareHtmlRender(['html' => $html, 'pagedBodyMargin' => 'zero', 'margins' => 0.0])->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand && trim($command->text) !== '') {
                $found[trim($command->text)] = $command;
            }
        }

        return $found;
    }

    public function testCellsAlignTheirContentWithinTheRow(): void
    {
        $texts = $this->texts(
            '<table style="border-collapse:collapse"><tr>'
            . '<td style="padding:0;height:100px;vertical-align:top">topo</td>'
            . '<td style="padding:0">meio</td>'
            . '<td style="padding:0;vertical-align:bottom">base</td>'
            . '<td style="padding:0" valign="bottom">atributo</td>'
            . '</tr></table>',
        );

        $line = $texts['topo']->run->height;
        self::assertEqualsWithDelta(0.0, $texts['topo']->y, 0.01);
        self::assertEqualsWithDelta((100.0 - $line) / 2, $texts['meio']->y, 0.01);
        self::assertEqualsWithDelta(100.0 - $line, $texts['base']->y, 0.01);
        self::assertEqualsWithDelta(100.0 - $line, $texts['atributo']->y, 0.01);
    }

    public function testDeclaredHeightLeavesRoomThatValignUses(): void
    {
        $texts = $this->texts('<table><tr><td valign="bottom" height="80" style="padding:0">x</td></tr></table>');

        self::assertEqualsWithDelta(80.0 - $texts['x']->run->height, $texts['x']->y, 0.01);
    }

    public function testLinesInsideACellKeepTheParagraphLinePitch(): void
    {
        $cell = $this->texts('<table><tr><td style="padding:0">um<br>dois<br>tres</td></tr></table>');
        $paragraph = $this->texts('<p style="margin:0">um<br>dois<br>tres</p>');

        self::assertEqualsWithDelta($paragraph['dois']->y - $paragraph['um']->y, $cell['dois']->y - $cell['um']->y, 0.01);
        self::assertEqualsWithDelta($paragraph['tres']->y - $paragraph['dois']->y, $cell['tres']->y - $cell['dois']->y, 0.01);
    }
}
