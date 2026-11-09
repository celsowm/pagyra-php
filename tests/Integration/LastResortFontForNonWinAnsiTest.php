<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * Text the CSS stack does not cover is drawn in a Base14 font, which can only write WinAnsi
 * (CP1252). Every code point outside it used to be written as a literal `?`: "associado a β2
 * agonista" came out as "associado a ?2 agonista" in a sentenca, and the U+2003 em space that
 * eproc uses to indent paragraphs, the U+202F narrow no-break space, ≥, bullets and Cyrillic
 * letters all turned into question marks. Those code points now go to the bundled DejaVu Sans,
 * embedded as a subset, while everything WinAnsi can carry stays on Base14.
 */
final class LastResortFontForNonWinAnsiTest extends TestCase
{
    private function pdf(string $body): string
    {
        return Pagyra::renderHtmlToPdf([
            'html' => '<p style="font-family:Times New Roman">' . $body . '</p>',
        ]);
    }

    /** @return list<string> the WinAnsi strings painted with Tj */
    private function base14Strings(string $pdf): array
    {
        preg_match_all('/^BT\n.*?^ET$/ms', $pdf, $blocks);
        preg_match_all('/^\((.*)\) Tj$/m', implode("\n", $blocks[0]), $matches);
        return $matches[1];
    }

    public function testGreekLetterIsDrawnFromTheLastResortFontInsteadOfAQuestionMark(): void
    {
        $pdf = $this->pdf('associado a β2 agonista');

        self::assertStringContainsString('/BaseFont /Times-Roman', $pdf);
        self::assertSame(1, preg_match('#/BaseFont /DejaVuSans-[0-9A-F]{8}#', $pdf));
        self::assertStringContainsString('/Encoding /Identity-H', $pdf);
        self::assertMatchesRegularExpression('/<[0-9A-F]{4}> <03B2>/', $pdf);
        self::assertSame(['associado a ', '2 agonista'], $this->base14Strings($pdf));
    }

    public function testTypographicSpacesKeepTheirWidthInsteadOfBecomingQuestionMarks(): void
    {
        $pdf = $this->pdf("X\u{2003}Y X\u{202F}Y X\u{2009}Y X\u{2011}Y");

        foreach (['2003', '202F', '2009', '2011'] as $hex) {
            self::assertMatchesRegularExpression('/<[0-9A-F]{4}> <' . $hex . '>/', $pdf);
        }
        foreach ($this->base14Strings($pdf) as $string) {
            self::assertStringNotContainsString('?', $string);
        }
    }

    public function testEmSpaceIsMeasuredAsOneEmSoTheLineIsWiderThanWithAnOrdinarySpace(): void
    {
        $narrow = Pagyra::prepareHtmlRender(['html' => '<span style="font-family:Times New Roman;font-size:20px">X Y</span>']);
        $wide = Pagyra::prepareHtmlRender(['html' => "<span style=\"font-family:Times New Roman;font-size:20px\">X\u{2003}Y</span>"]);

        $width = static function ($prepared): float {
            $max = 0.0;
            foreach ($prepared->displayList->pages as $page) {
                foreach ($page->commands as $command) {
                    if ($command instanceof \Pagyra\Paint\TextPaintCommand) $max = max($max, $command->run->width);
                }
            }
            return $max;
        };

        // Times space is 0.25em (5px at 20px); the DejaVu em space is a full 20px.
        self::assertEqualsWithDelta($width($narrow) + 15.0, $width($wide), 0.5);
    }

    public function testPlainWinAnsiTextEmbedsNoFont(): void
    {
        $pdf = $this->pdf('Sentença – “Tutela de urgência” € §');

        self::assertStringNotContainsString('/Type0', $pdf);
        self::assertStringNotContainsString('DejaVuSans', $pdf);
    }

    public function testNamingTheFallbackFamilyInCssDoesNotMoveOrdinaryTextOffBase14(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p style="font-family:\'DejaVu Sans\', sans-serif">Texto comum</p>',
        ]);

        self::assertStringContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringNotContainsString('/Type0', $pdf);
    }
}
