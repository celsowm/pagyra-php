<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Fonts\Base14\Base14WidthTable;
use Pagyra\Fonts\FontFamilyList;
use Pagyra\Pagyra;
use Pagyra\Paint\TextPaintCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comma inside quotes is part of a family name, not a list separator. The CKEditor template of
 * the JFRJ and TRF2 documents declares `font-family: "Calibri, sans-serif"`: one family, called
 * `Calibri, sans-serif`, that no system has, so browsers — and wkhtmltopdf — draw that text in the
 * default serif face. Splitting at the comma read it as Calibri with a sans-serif fallback and
 * drew it in Helvetica, wider than what the same document looks like everywhere else.
 */
final class QuotedFontFamilyWithCommaTest extends TestCase
{
    /** @return array<string, array{0:string, 1:list<string>}> */
    public static function lists(): array
    {
        return [
            'unquoted list' => ['Calibri, Arial, sans-serif', ['Calibri', 'Arial', 'sans-serif']],
            'quoted names' => ['"Calibri", \'Open Sans\' , serif', ['Calibri', 'Open Sans', 'serif']],
            'whole list in double quotes' => ['"Calibri, sans-serif"', ['Calibri, sans-serif']],
            'whole list in single quotes' => ["'Calibri, sans-serif'", ['Calibri, sans-serif']],
            'quoted comma then fallback' => ['"Calibri, sans-serif", Arial', ['Calibri, sans-serif', 'Arial']],
            'empty entries dropped' => [' , Arial,, ', ['Arial']],
            'nothing' => ['', []],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('lists')]
    public function testCommaSplitsOnlyOutsideQuotes(string $value, array $expected): void
    {
        self::assertSame($expected, FontFamilyList::names($value));
    }

    /** @return list<TextPaintCommand> */
    private function textCommands(string $css): array
    {
        $commands = Pagyra::prepareHtmlRender([
            'html' => '<style>p { ' . $css . ' }</style><p>Sistema Unico de Saude</p>',
        ])->displayList->pages[0]->commands;
        return array_values(array_filter($commands, static fn(object $c): bool => $c instanceof TextPaintCommand));
    }

    public function testWholeListInQuotesIsDrawnAndMeasuredInTheDefaultSerif(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(['html' => '<style>p { font-family: "Calibri, sans-serif"; }</style><p>Sistema Unico de Saude</p>']);

        self::assertStringContainsString('/BaseFont /Times-Roman', $pdf);
        self::assertStringNotContainsString('/BaseFont /Helvetica', $pdf);

        // Measured with the face it is drawn with, so the next run starts where this one ends.
        $run = $this->textCommands('font-family: "Calibri, sans-serif";')[0]->run;
        self::assertSame('Times-Roman', Base14WidthTable::resolveFont($run->style));
    }

    public function testAQuotedNameWithACommaIsSkippedLikeAnyUnknownName(): void
    {
        $pdf = Pagyra::renderHtmlToPdf(['html' => '<style>p { font-family: "Calibri, serif", Arial; }</style><p>texto</p>']);

        self::assertStringContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringNotContainsString('/BaseFont /Times-Roman', $pdf);
    }
}
