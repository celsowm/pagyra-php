<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class TableCellFillsRowHeightTest extends TestCase
{
    private function paragraphs(int $howMany): string
    {
        $html = '';
        for ($i = 1; $i <= $howMany; $i++) $html .= "<p style=\"margin:0\">linha {$i}</p>";
        return $html;
    }

    /**
     * The motivating bug: a `<figure class="table">` from CKEditor whose two cells hold
     * lists of different lengths. The shorter cell kept its natural height, so the rule
     * dividing the two columns and the shorter cell's own borders stopped short of the
     * row's bottom edge while the taller cell and the table frame reached it.
     */
    public function testShortCellIsStretchedToTheRowHeightBesideATallerSibling(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tr>'
                . '<td style="padding:0">' . $this->paragraphs(2) . '</td>'
                . '<td style="padding:0">' . $this->paragraphs(8) . '</td>'
                . '</tr></table>',
            'viewportWidth' => 400,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        [$shortCell, $tallCell] = $row->children;

        self::assertSame('td', $shortCell->source->node->tagName);
        $rowHeight = $row->box->content->height;

        self::assertEqualsWithDelta($rowHeight, $shortCell->box->borderBox()->height, 0.01);
        self::assertEqualsWithDelta($rowHeight, $tallCell->box->borderBox()->height, 0.01);
    }

    public function testStretchingACellDoesNotMoveItsContentDownFromTheTop(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tr>'
                . '<td style="padding:0"><p style="margin:0">so uma linha</p></td>'
                . '<td style="padding:0">' . $this->paragraphs(6) . '</td>'
                . '</tr></table>',
            'viewportWidth' => 400,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        $shortCell = $row->children[0];
        $firstLine = $shortCell->children[0];

        // Content stays anchored at the cell's top edge (no vertical-align support yet).
        self::assertEqualsWithDelta($shortCell->box->content->y, $firstLine->box->content->y, 0.01);
    }

    public function testSingleCellRowIsUnaffected(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => '<table><tr><td style="padding:0">' . $this->paragraphs(3) . '</td></tr></table>',
            'viewportWidth' => 400,
        ]);

        $row = $prepared->layoutRoot->children[0]->children[0];
        $cell = $row->children[0];

        self::assertEqualsWithDelta($row->box->content->height, $cell->box->borderBox()->height, 0.01);
    }
}
