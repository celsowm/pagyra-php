<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

final class TableCellHeightIsMinimumTest extends TestCase
{
    private function paintedTextCommands(string $html): int
    {
        $prepared = Pagyra::prepareHtmlRender(['html' => $html]);
        $count = 0;
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand) $count++;
            }
        }
        return $count;
    }

    private function paragraphs(int $howMany): string
    {
        $html = '';
        for ($i = 1; $i <= $howMany; $i++) $html .= "<p style=\"margin:0\">linha {$i}</p>";
        return $html;
    }

    public function testCellGrowsToFitContentThatExceedsItsDeclaredHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tr><td style="height:18px">' . $this->paragraphs(5) . '</td></tr></table>',
            'viewportWidth' => 400,
        ]);

        $cell = $prepared->layoutRoot->children[0]->children[0]->children[0];
        self::assertSame('td', $cell->source->node->tagName);
        self::assertGreaterThan(18.0, $cell->box->content->height);
    }

    public function testDeclaredHeightStillActsAsALowerBound(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tr><td style="height:200px;padding:0">pouco texto</td></tr></table>',
            'viewportWidth' => 400,
        ]);

        $cell = $prepared->layoutRoot->children[0]->children[0]->children[0];
        self::assertSame(200.0, $cell->box->content->height);
    }

    public function testRowGrowsWithTheTallestCell(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tr><td style="height:10px;padding:0">a</td>'
                . '<td style="height:10px;padding:0">' . $this->paragraphs(4) . '</td></tr></table>',
            'viewportWidth' => 400,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        self::assertGreaterThan(10.0, $row->box->content->height);
    }

    public function testContentOverflowingADeclaredCellHeightStillPaintsOnLaterPages(): void
    {
        // The motivating bug: the cell reported its declared height, so the table's box did not
        // cover its own content and pagination never claimed anything past the first page.
        $withHeight = $this->paintedTextCommands(
            '<table><tr><td style="height:18px">' . $this->paragraphs(90) . '</td></tr></table>',
        );
        $withoutHeight = $this->paintedTextCommands(
            '<table><tr><td>' . $this->paragraphs(90) . '</td></tr></table>',
        );

        self::assertSame(90, $withoutHeight);
        self::assertSame($withoutHeight, $withHeight);
    }

    public function testABlockOutsideATableStillTreatsHeightAsFixed(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<div style="height:18px">' . $this->paragraphs(5) . '</div>',
            'viewportWidth' => 400,
        ]);

        self::assertSame(18.0, $prepared->layoutRoot->children[0]->box->content->height);
    }
}
