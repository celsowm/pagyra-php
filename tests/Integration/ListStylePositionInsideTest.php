<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\TestCase;

/**
 * `list-style-position: inside` (CSS Lists 3 §7.1) puts the marker in the flow of the item's own
 * text instead of the list's left padding (`outside`, the default). Only `outside` was painted
 * (DisplayListBuilder's `appendListMarker`, positioned purely at paint time with no layout space
 * reserved for it), so an `inside` marker was invisible: it carried no box of its own and nothing
 * ever wove it into the text.
 */
final class ListStylePositionInsideTest extends TestCase
{
    /** @return list<string> */
    private static function tjStrings(string $pdf): array
    {
        preg_match_all('/\((.*?)\) Tj/', $pdf, $matches);
        return $matches[1];
    }

    public function testMarkerReachesTheTextStreamLikeOutside(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ol style="list-style-position:inside"><li>alpha</li><li>beta</li></ol>',
        ]);

        self::assertSame(['1. alpha', '2. beta'], self::tjStrings($pdf));
    }

    public function testMarkerAndTextShareTheSameLineAtTheContentEdgeNotTheGutter(): void
    {
        $outside = Pagyra::prepareHtmlRender(['pagedBodyMargin' => 'zero', 'margins' => 0.0, 'html' => '<ol style="margin:0;padding-left:40px"><li>alpha</li></ol>']);
        $inside = Pagyra::prepareHtmlRender(['pagedBodyMargin' => 'zero', 'margins' => 0.0, 'html' => '<ol style="margin:0;padding-left:40px;list-style-position:inside"><li>alpha</li></ol>']);

        $insideText = null;
        foreach ($inside->displayList->pages[0]->commands as $command) {
            if ($command instanceof TextPaintCommand) { $insideText = $command; break; }
        }
        self::assertNotNull($insideText);
        self::assertSame('1. alpha', $insideText->text);
        // Only one text-paint command: the marker was woven into the line's own text run instead
        // of being a second, separately positioned one the way `outside` paints it.
        $insideTexts = array_values(array_filter($inside->displayList->pages[0]->commands, static fn($c) => $c instanceof TextPaintCommand));
        self::assertCount(1, $insideTexts);
        $outsideTexts = array_values(array_filter($outside->displayList->pages[0]->commands, static fn($c) => $c instanceof TextPaintCommand));
        self::assertCount(2, $outsideTexts);
        // `inside` starts at the list's own content edge (after the 40px padding), not further
        // left in the gutter the way `outside`'s separate marker run does.
        self::assertEqualsWithDelta(40.0, $insideText->x, 0.5);
    }

    public function testWrapsTheFollowingTextInsteadOfOverlappingIt(): void
    {
        // A narrow item: with the marker correctly reserving layout space, "muito" cannot fit on
        // the marker's line and wraps to a second one.
        $prepared = Pagyra::prepareHtmlRender([
            'pagedBodyMargin' => 'zero',
            'html' => '<ol style="margin:0;width:60px;font-size:16px;list-style-position:inside"><li>texto muito longo aqui</li></ol>',
            'viewportWidth' => 60,
            'viewportHeight' => 400,
        ]);

        $li = $prepared->layoutRoot->children[0]->children[0];
        self::assertGreaterThan(1, count($li->lineBoxes));
        self::assertStringStartsWith('1.', $li->lineBoxes[0]->text);
    }

    public function testBulletMarkerIsWovenInTooAsPlainText(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ul style="list-style-position:inside"><li>um</li></ul>',
        ]);

        // U+2022 BULLET is WinAnsi/CP1252 byte 0x95, not UTF-8, in the PDF's text stream.
        self::assertStringContainsString("(\x95 um) Tj", $pdf);
    }

    public function testFallsBackToOutsidePlacementWhenTheItemsOwnContentIsABlock(): void
    {
        // <li><p>...</p></li>: the item has no inline segment of its own for the marker to join,
        // so it still needs to appear somewhere instead of silently vanishing.
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ol style="list-style-position:inside"><li><p>primeiro</p></li></ol>',
        ]);

        self::assertSame(['1.', 'primeiro'], self::tjStrings($pdf));
    }

    public function testOutsideRemainsThePaintOnlyMarkerUnaffected(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(['html' => '<ol><li>alpha</li></ol>']);

        self::assertSame(['1.', 'alpha'], self::tjStrings($pdf));
    }
}
