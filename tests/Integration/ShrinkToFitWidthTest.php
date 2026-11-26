<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * Content wider than the page (a table pasted from Word with `width: 1339px`) was drawn past the
 * right margin and cut at the edge of the sheet, losing about a third of every line in a TJRJ
 * decision. wkhtmltopdf scales the whole document down until it fits ("smart shrinking");
 * `minContentScale` does the same, and only when the smaller layout really fits.
 */
final class ShrinkToFitWidthTest extends TestCase
{
    private const WIDE_TABLE = '<p>Antes da tabela.</p><table style="width:900px;border-collapse:collapse;background:#eee" cellspacing="0" cellpadding="0"><tr><td>'
        . '<p>AGRAVO DE INSTRUMENTO. DIREITO TRIBUTARIO. EXECUCAO INDIVIDUAL DE SENTENCA COLETIVA. PENSAO ESPECIAL.</p></td></tr></table>';

    /** A 1x1 PNG: as wide as its `width` attribute, capped by `max-width`. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** Page 400x200 px with 20 px margins: 360 px of content, 450 px of layout at contentScale 0.8. */
    private static function options(string $html, array $extra = []): array
    {
        return $extra + [
            'html' => '<html><body>' . $html . '</body></html>',
            'viewportWidth' => 400,
            'viewportHeight' => 200,
            'pageWidth' => 400,
            'pageHeight' => 200,
            'margins' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20],
            'contentScale' => 0.8,
        ];
    }

    private static function drawingScale(string $pdf): float
    {
        self::assertSame(1, preg_match('/q\n([0-9.]+) 0 0 ([0-9.]+) 0 0 cm\n/', $pdf, $match));
        self::assertSame($match[1], $match[2]);

        return (float) $match[1];
    }

    public function testWithoutTheOptionAWideTableKeepsTheScale(): void
    {
        self::assertSame(0.8, self::drawingScale(Pagyra::renderHtmlToPdf(self::options(self::WIDE_TABLE))));
    }

    public function testAWideTableIsScaledDownUntilItFits(): void
    {
        $scale = self::drawingScale(Pagyra::renderHtmlToPdf(self::options(self::WIDE_TABLE, ['minContentScale' => 0.3])));

        // the background paints all 900 px of the table, after the body's 8 px margin, on 360 px of content
        self::assertLessThan(0.8, $scale);
        self::assertGreaterThanOrEqual(0.3, $scale);
        self::assertLessThanOrEqual(360.0 + 1.0, (900 + 8) * $scale);
    }

    public function testAWordWithNoBreakOpportunityIsScaledDownToo(): void
    {
        $scale = self::drawingScale(Pagyra::renderHtmlToPdf(self::options('<p>' . str_repeat('abcdefghij', 12) . '</p>', ['minContentScale' => 0.3])));

        self::assertLessThan(0.8, $scale);
    }

    public function testWhenEvenTheMinimumDoesNotFitTheFirstLayoutStays(): void
    {
        self::assertSame(
            Pagyra::renderHtmlToPdf(self::options(self::WIDE_TABLE)),
            Pagyra::renderHtmlToPdf(self::options(self::WIDE_TABLE, ['minContentScale' => 0.6])),
        );
    }

    public function testContentThatFitsIsRenderedExactlyAsBefore(): void
    {
        $html = '<p style="text-align:justify">Texto comum que cabe na largura da pagina e quebra nos espacos.</p>';

        self::assertSame(
            Pagyra::renderHtmlToPdf(self::options($html)),
            Pagyra::renderHtmlToPdf(self::options($html, ['minContentScale' => 0.3])),
        );
    }

    public function testContentThatGrowsWithThePageIsNotShrunkForNothing(): void
    {
        // The image is as wide as the paragraph and starts at the indent, so it overflows by the
        // indent at any scale: shrinking would only make the text smaller.
        $html = '<p style="text-indent:60px"><img src="' . self::PNG . '" width="2000" height="10" style="height:auto;max-width:100%"></p>';

        self::assertSame(
            Pagyra::renderHtmlToPdf(self::options($html)),
            Pagyra::renderHtmlToPdf(self::options($html, ['minContentScale' => 0.3])),
        );
    }

    public function testATableClippedByItsContainerDoesNotCount(): void
    {
        $html = '<div style="overflow:hidden;width:300px">' . self::WIDE_TABLE . '</div>';

        self::assertSame(0.8, self::drawingScale(Pagyra::renderHtmlToPdf(self::options($html, ['minContentScale' => 0.3]))));
    }

    public function testTheMinimumCannotExceedTheScale(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Pagyra::renderHtmlToPdf(self::options(self::WIDE_TABLE, ['minContentScale' => 0.9]));
    }
}
