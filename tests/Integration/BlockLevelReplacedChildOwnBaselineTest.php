<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Layout\BlockLayoutEngine;
use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * `display:inline-block; height:0` wrapping a `display:block` <img> — the eproc/JFRJ letterhead's
 * brasão-out-of-flow trick, already covered empty-bodied by CollapsedInlineBlockLineTest and for
 * horizontal shift by NegativeMarginInlineBlockImageTest — must not, once the box has that image
 * inside it, push the following sibling down or drop the image from the PDF.
 *
 * collectTokens() has no real block formatting context for a block-level replaced child, so it
 * approximates one by tokenizing the image as an atomic box on a synthetic line of its own inside
 * the wrapper's internal layout. atomicBoxBaseline() (InlineBlockLastLineBaselineTest) reads the
 * *last* in-flow line of an inline-block to place it on its parent's baseline — right for real
 * wrapped text, but that synthetic line is not a real line box (CSS 2.1 9.2.1.1/10.8.1: a box
 * whose content is block-level has none), and reading a baseline out of it pushed everything after
 * the wrapper down by roughly the image's own height. Once InlineTextFormatter correctly falls
 * back to the empty-box behavior for that case, the wrapper's own line collapses to exactly zero
 * height again — legitimately, since `height:0 !important` says so — which uncovered a second,
 * independent bug: PaginationEngine::blockFragmentForPage() treats a subtree whose extent is a
 * single point sitting exactly on a page's own content start as touching neither page, dropping
 * the anonymous block BlockLayoutEngine wraps that line in (and the image nested inside it) from
 * every page's fragment list — silently, with no warning, exactly the failure mode item 5 of
 * AGENTS.md warns against.
 */
final class BlockLevelReplacedChildOwnBaselineTest extends TestCase
{
    private const LOGO_HTML = '<div>'
        . '<div style="display:inline-block;width:100%%;height:0">'
        . '<img src="%s" style="display:block;width:40px;height:60px;margin:-10px 0 0 -10px">'
        . '</div><p style="margin:0">timbre</p>'
        . '</div>';

    private function pngDataUrl(): string
    {
        $idat = gzcompress("\x00\xff\x00\x00");
        self::assertIsString($idat);
        $ihdr = pack('N', 1) . pack('N', 1) . chr(8) . chr(2) . "\x00\x00\x00";
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', $idat) . $chunk('IEND', '');

        return 'data:image/png;base64,' . base64_encode($png);
    }

    public function testFollowingSiblingIsNotPushedDownByTheImagesOwnHeight(): void
    {
        $prepared = Pagyra::prepareHtmlRender([
            'html' => sprintf(self::LOGO_HTML, $this->pngDataUrl()),
            'viewportWidth' => 300,
            'viewportHeight' => 400,
        ]);
        $wrapper = $prepared->layoutRoot->children[0];

        // The wrapper's own line stays collapsed to zero, exactly like the empty-bodied case in
        // CollapsedInlineBlockLineTest, and the following <p> sits right at the top — not ~50px
        // down (the image's 60px height plus its -10px top margin) where the bug left it.
        $anonymous = $wrapper->children[0];
        self::assertSame(BlockLayoutEngine::ANONYMOUS_TAG, $anonymous->source->node->tagName);
        self::assertSame(0.0, $anonymous->lineBoxes[0]->height);
        self::assertSame(0.0, $wrapper->children[1]->box->content->y);
    }

    public function testImageStillReachesThePdf(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => sprintf(self::LOGO_HTML, $this->pngDataUrl()),
            'viewportWidth' => 300,
            'viewportHeight' => 400,
        ]);

        self::assertSame(
            1,
            preg_match('/[\d.]+ 0 0 [\d.]+ -?[\d.]+ [\d.]+ cm\s*\/Im1 Do/', $pdf),
            'expected an image draw matrix in the PDF content stream',
        );
    }
}
