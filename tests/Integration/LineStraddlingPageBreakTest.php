<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Paint\ImagePaintCommand;
use Pagyra\Paint\TextPaintCommand;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * A line holding an image is never split across pages: when it would straddle a break, the whole
 * line moves to the top of the next page and everything after it follows. Before, the line went
 * to the page its baseline was on but kept its continuous position, so a tall inline image (a
 * scanned form in a court document) was painted across the break and lost its top, or its
 * bottom, off the sheet. Text-only lines keep their old placement.
 */
final class LineStraddlingPageBreakTest extends TestCase
{
    private const PAGE = '@page { size:300px 200px; margin:20px; }';

    public function testTallInlineImageCrossingTheBreakStartsAtTopOfNextPage(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }</style>'
                . '<p>one</p><p>two</p><p>three</p><p>four</p>'
                . '<p><img src="' . $this->pngDataUrl(100, 120) . '" style="display:inline;width:100px;height:120px"></p>'
                . '<p>after</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $image = $this->images($prepared);
        self::assertCount(1, $image);
        self::assertSame(1, $image[0]->pageIndex);
        self::assertEqualsWithDelta(20.0, $image[0]->y, 0.01, 'image must start at the top of the content area, not above it');

        $after = $this->textCommand($prepared, 'after');
        self::assertSame(1, $after->pageIndex);
        self::assertGreaterThanOrEqual($image[0]->y + $image[0]->height - 0.01, $after->y, 'content after the image follows it');
    }

    public function testLineInsideAParagraphMovesWithEverythingAfterIt(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; widows:1; orphans:1; }</style>'
                . '<p>one<br>two<br>three<br>four<br>'
                . '<img src="' . $this->pngDataUrl(100, 120) . '" style="width:100px;height:120px"><br>tail</p>'
                . '<p>after</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $image = $this->images($prepared);
        self::assertCount(1, $image);
        self::assertSame(1, $image[0]->pageIndex);
        self::assertGreaterThanOrEqual(20.0 - 0.01, $image[0]->y);

        $four = $this->textCommand($prepared, 'four');
        self::assertSame(0, $four->pageIndex, 'lines before the break stay where they were');

        $tail = $this->textCommand($prepared, 'tail');
        $after = $this->textCommand($prepared, 'after');
        self::assertSame(1, $tail->pageIndex);
        self::assertSame(1, $after->pageIndex);
        self::assertGreaterThan($image[0]->y + $image[0]->height - 0.01, $tail->y);
        self::assertGreaterThan($tail->y, $after->y);
    }

    public function testParagraphBorderGrowsWithTheMovedLine(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }'
                . ' div.box { border-bottom:2px solid black; }</style>'
                . '<p>one</p><p>two</p><p>three</p><p>four</p>'
                . '<div class="box"><img src="' . $this->pngDataUrl(100, 120) . '" style="width:100px;height:120px"></div>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $placement = $prepared->pagination->placements[4];
        self::assertSame(1, $placement->endPageIndex);
        $fragment = $placement->fragments[array_key_last($placement->fragments)];
        self::assertSame(1, $fragment->pageIndex);
        self::assertGreaterThanOrEqual(120.0, $fragment->pageY + $fragment->height, 'the box still wraps the image it was pushed with');
    }

    public function testLineTallerThanAPageMovesWhenLessThanHalfAPageIsLeft(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }</style>'
                . '<p>one</p><p>two</p><p>three</p><p>four</p><p>five</p>'
                . '<p><img src="' . $this->pngDataUrl(100, 220) . '" style="width:100px;height:220px"></p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $image = $this->images($prepared);
        self::assertCount(1, $image);
        self::assertSame(1, $image[0]->pageIndex, 'its top goes where most of it shows, not above the page its baseline is on');
        self::assertEqualsWithDelta(20.0, $image[0]->y, 0.01);
    }

    public function testLineTallerThanAPageStaysWhenHalfAPageIsLeft(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }</style>'
                . '<p>one</p>'
                . '<p><img src="' . $this->pngDataUrl(100, 220) . '" style="width:100px;height:220px"></p><p>after</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $image = $this->images($prepared);
        self::assertSame(0, $image[0]->pageIndex, 'it belongs to the page its top is on, even with its baseline pages below');
        self::assertEqualsWithDelta(40.0, $image[0]->y, 0.01);
        self::assertSame(0.0, $prepared->pagination->placements[2]->offsetY, 'moving it would only waste most of a page');
    }

    public function testLineTallerThanAPageAlreadyAtPageTopStaysThere(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }</style>'
                . '<p><img src="' . $this->pngDataUrl(100, 220) . '" style="width:100px;height:220px"></p><p>after</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $image = $this->images($prepared);
        self::assertSame(0, $image[0]->pageIndex);
        self::assertEqualsWithDelta(20.0, $image[0]->y, 0.01);
        self::assertSame(0.0, $prepared->pagination->placements[1]->offsetY);
    }

    public function testTextLineWhollyOnOnePageDoesNotMove(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }</style>'
                . '<p>one</p><p>two</p><p>three</p><p>four</p><p>five</p><p>six</p><p>seven</p><p>eight</p><p>nine</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $one = $this->textCommand($prepared, 'one');
        $nine = $this->textCommand($prepared, 'nine');
        self::assertSame(1, $nine->pageIndex);
        self::assertEqualsWithDelta($one->y, $nine->y, 0.01, 'a line that fits its page keeps the same place a first line has');
    }

    public function testTextLineCrossingTheBreakKeepsItsPlace(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; } .spacer { height:10px; }</style>'
                . '<div class="spacer"></div><p>one</p><p>two</p><p>three</p><p>four</p><p>five</p><p>six</p><p>seven</p><p>eight</p><p>nine</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        foreach ($prepared->pagination->placements as $placement) {
            self::assertSame(0.0, $placement->offsetY);
        }
        $fragments = $prepared->pagination->placements[8]->fragments;
        $eight = $fragments[array_key_last($fragments)]->lines[0];
        self::assertSame(1, $eight->pageIndex, 'it goes to the page its baseline is on, as before');
        self::assertEqualsWithDelta(-10.0, $eight->pageY, 0.01);
    }

    public function testTableRowWithImageCrossingTheBreakMovesWhole(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }'
                . ' table { border-spacing:0; } td { padding:0; }</style>'
                . '<p>one</p><p>two</p><p>three</p><p>four</p>'
                . '<table><tr><td>label</td><td><img src="' . $this->pngDataUrl(100, 120) . '" style="width:100px;height:120px"></td></tr>'
                . '<tr><td>next</td><td>row</td></tr></table><p>after</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $image = $this->images($prepared);
        self::assertCount(1, $image);
        self::assertSame(1, $image[0]->pageIndex);
        self::assertEqualsWithDelta(20.0, $image[0]->y, 0.01, 'the row starts at the top of the content area');

        $label = $this->textCommand($prepared, 'label');
        self::assertSame(1, $label->pageIndex, 'the cell beside the image goes with it');
        self::assertLessThan($image[0]->y + $image[0]->height, $label->y);

        $next = $this->textCommand($prepared, 'next');
        self::assertSame(1, $next->pageIndex);
        self::assertGreaterThanOrEqual($image[0]->y + $image[0]->height - 0.01, $next->y, 'the rows after it follow');
    }

    public function testRowspanCellCrossingTheBreakMovesItsRow(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }'
                . ' table { border-spacing:0; } td { padding:0; }</style>'
                . '<p>one</p><p>two</p><p>three</p>'
                . '<table><tr><td>a</td><td rowspan="2"><img src="' . $this->pngDataUrl(100, 120) . '" style="width:100px;height:120px"></td></tr>'
                . '<tr><td>b</td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $image = $this->images($prepared);
        self::assertSame(1, $image[0]->pageIndex);
        self::assertEqualsWithDelta(20.0, $image[0]->y, 0.01);
        self::assertSame(1, $this->textCommand($prepared, 'a')->pageIndex);
    }

    public function testImageInACellTallerThanAPageMovesAlone(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<style>' . self::PAGE . ' p { margin:0; font-size:16px; line-height:20px; }'
                . ' table { border-spacing:0; } td { padding:0; }</style>'
                . '<table><tr><td><p>one</p><p>two</p><p>three</p><p>four</p>'
                . '<p><img src="' . $this->pngDataUrl(100, 120) . '" style="width:100px;height:120px"></p>'
                . '<p>after</p><p>x</p><p>y</p><p>z</p></td></tr></table>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        $image = $this->images($prepared);
        self::assertSame(1, $image[0]->pageIndex);
        self::assertEqualsWithDelta(20.0, $image[0]->y, 0.01);
        self::assertSame(0, $this->textCommand($prepared, 'four')->pageIndex, 'the lines before it stay where they were');
        $after = $this->textCommand($prepared, 'after');
        self::assertGreaterThanOrEqual($image[0]->y + $image[0]->height - 0.01, $after->y);
    }

    /** @return list<ImagePaintCommand> */
    private function images(object $prepared): array
    {
        $found = [];
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof ImagePaintCommand) $found[] = $command;
            }
        }
        return $found;
    }

    private function textCommand(object $prepared, string $text): TextPaintCommand
    {
        foreach ($prepared->displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if ($command instanceof TextPaintCommand && trim($command->text) === $text) return $command;
            }
        }
        self::fail("text '$text' was not painted");
    }

    private function pngDataUrl(int $width, int $height): string
    {
        $bytes = "\x89PNG\r\n\x1a\n"
            . pack('N', 13)
            . 'IHDR'
            . pack('N', $width)
            . pack('N', $height)
            . "\x08\x06\x00\x00\x00"
            . "\x00\x00\x00\x00";

        return 'data:image/png;base64,' . base64_encode($bytes);
    }
}
