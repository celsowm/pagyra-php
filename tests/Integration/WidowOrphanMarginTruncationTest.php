<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * CSS 2.1 13.3.3 and CSS Fragmentation 5.2: when a block moves to the next page at an unforced
 * break, the margin adjoining that break is truncated. The widows/orphans push aligned the
 * block's margin box with the page start, so its top margin stayed at the top of the new page,
 * where wkhtmltopdf leaves nothing; in eproc documents that 5mm was often what sent the footer to
 * a page of its own.
 */
final class WidowOrphanMarginTruncationTest extends TestCase
{
    private function paragraphPlacement(string $paragraphCss): \Pagyra\Pagination\PagePlacement
    {
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => "<style>@page { size:300px 100px; margin:10px; } .spacer { height:30px; margin:0; } p { white-space:pre; font-size:16px; line-height:20px; {$paragraphCss} }</style><div class=\"spacer\"></div><p>one\ntwo\nthree</p>",
            'viewportWidth' => 300,
            'viewportHeight' => 100,
        ]);

        self::assertNotNull($prepared->pagination);
        self::assertSame(80.0, $prepared->pagination->flow->contentHeight);

        return $prepared->pagination->placements[1];
    }

    public function testMovedParagraphStartsTheNextPageWithItsBorderBox(): void
    {
        // Margin box at 30, border box at 40; the third line would be a widow, so the paragraph
        // moves. Its border box goes to 80 (the start of page 2), not 90.
        $paragraph = $this->paragraphPlacement('margin:10px 0 0;');

        self::assertSame(40.0, $paragraph->offsetY);
        self::assertSame(1, $paragraph->endPageIndex);
        $lastFragment = $paragraph->fragments[array_key_last($paragraph->fragments)];
        self::assertSame(1, $lastFragment->pageIndex);
        self::assertCount(3, $lastFragment->lines);
        // Only the truncated margin is left on page 1: no line of the paragraph is painted there.
        foreach ($paragraph->fragments as $fragment) {
            if ($fragment->pageIndex === 0) self::assertSame([], $fragment->lines);
        }
    }

    public function testParagraphWithoutMarginMovesAsBefore(): void
    {
        $paragraph = $this->paragraphPlacement('margin:0; widows:3;');

        self::assertSame(1, $paragraph->pageIndex);
        self::assertSame(50.0, $paragraph->offsetY);
    }
}
