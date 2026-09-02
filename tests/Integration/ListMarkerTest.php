<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class ListMarkerTest extends TestCase
{
    /** @return list<string> */
    private static function tjStrings(string $pdf): array
    {
        preg_match_all('/\((.*?)\) Tj/', $pdf, $matches);
        return $matches[1];
    }

    public function testOrderedListNumbersItemsInDocumentOrder(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ol><li>alpha</li><li>beta</li><li>gamma</li></ol>',
        ]);

        self::assertSame(['1.', 'alpha', '2.', 'beta', '3.', 'gamma'], self::tjStrings($pdf));
    }

    public function testUnorderedListDrawsABulletBeforeEachItem(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(['html' => '<ul><li>um</li><li>dois</li></ul>']);

        // WinAnsi 0x95 is the bullet the Base14 encoder writes for U+2022.
        self::assertSame(["\x95", 'um', "\x95", 'dois'], self::tjStrings($pdf));
    }

    public function testOrderedListHonoursTheStartAttribute(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ol start="12"><li>c</li><li>d</li></ol>',
        ]);

        self::assertSame(['12.', 'c', '13.', 'd'], self::tjStrings($pdf));
    }

    public function testListItemValueAttributeOverridesTheRunningCounter(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ol><li>a</li><li value="7">b</li><li>c</li></ol>',
        ]);

        self::assertSame(['1.', 'a', '7.', 'b', '8.', 'c'], self::tjStrings($pdf));
    }

    public function testListStyleTypeUpperRomanFormatsTheMarker(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ol style="list-style-type:upper-roman"><li>a</li><li>b</li><li>c</li><li>d</li></ol>',
        ]);

        self::assertSame(['I.', 'a', 'II.', 'b', 'III.', 'c', 'IV.', 'd'], self::tjStrings($pdf));
    }

    public function testListStyleNoneSuppressesTheMarker(): void
    {
        $longhand = Pagyra::renderHtmlToPdf(['html' => '<ul style="list-style-type:none"><li>x</li><li>y</li></ul>']);
        $shorthand = Pagyra::renderHtmlToPdf(['html' => '<ul style="list-style:none"><li>x</li><li>y</li></ul>']);

        self::assertSame(['x', 'y'], self::tjStrings($longhand));
        self::assertSame(['x', 'y'], self::tjStrings($shorthand));
    }

    public function testMarkerIsDrawnForAnItemWhoseContentSitsInAChildBlock(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ol><li><p>primeiro</p></li><li><p>segundo</p></li></ol>',
        ]);

        self::assertSame(['1.', 'primeiro', '2.', 'segundo'], self::tjStrings($pdf));
    }

    public function testNestedOrderedListsCountIndependently(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<ol><li>a<ol><li>a1</li><li>a2</li></ol></li><li>b</li></ol>',
        ]);

        self::assertSame(
            ['1.', 'a', '1.', 'a1', '2.', 'a2', '2.', 'b'],
            self::tjStrings($pdf),
        );
    }

    public function testMarkerSitsToTheLeftOfTheItemText(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(['html' => '<ol><li>alpha</li></ol>']);

        // Two "1 0 0 1 <x> <y> Tm" text-matrix ops: the marker then the item text,
        // on the same baseline, marker to the left.
        self::assertSame(2, preg_match_all('/1 0 0 1 (-?[\d.]+) (-?[\d.]+) Tm/', $pdf, $m));
        [$markerX, $textX] = [(float) $m[1][0], (float) $m[1][1]];
        [$markerY, $textY] = [(float) $m[2][0], (float) $m[2][1]];
        self::assertLessThan($textX, $markerX);
        self::assertEqualsWithDelta($textY, $markerY, 0.01);
    }
}
