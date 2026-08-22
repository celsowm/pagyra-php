<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PdfSerializationTest extends TestCase
{
    public function testRenderHtmlToPdfReturnsStructuredPdfWithValidXrefPointer(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>@page { size:200px 100px; margin:10px; }</style>'
                . '<p style="margin:0;font-family:Arial;font-weight:700;font-style:italic;color:#123456">Hello</p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/Type /Catalog', $pdf);
        self::assertStringContainsString('/Type /Pages', $pdf);
        self::assertStringContainsString('/Count 1', $pdf);
        self::assertStringContainsString('/MediaBox [0 0 150 75]', $pdf);
        self::assertStringContainsString('/BaseFont /Helvetica-BoldOblique', $pdf);
        self::assertStringContainsString('(Hello) Tj', $pdf);
        self::assertStringContainsString('xref', $pdf);
        self::assertStringContainsString('trailer', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);

        self::assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $match));
        $xrefOffset = (int) $match[1];
        self::assertSame('xref', substr($pdf, $xrefOffset, 4));
    }

    public function testPdfSerializesBackgroundAndWinAnsiText(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>@page { size:200px 100px; margin:10px; }</style>'
                . '<p style="margin:0;background-color:red;color:black">Olá – teste</p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString("1 0 0 rg\n", $pdf);
        self::assertStringContainsString("(Ol\xE1 \x96 teste) Tj", $pdf);
    }

    public function testBase14PdfPreservesLetterAndWordSpacing(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<p style="margin:0;font-family:Arial;font-size:10px;letter-spacing:2px;word-spacing:3px">A B</p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);

        self::assertStringContainsString("1.5 Tc\n", $pdf);
        self::assertStringContainsString("2.25 Tw\n", $pdf);
        self::assertStringContainsString('(A B) Tj', $pdf);
    }

    public function testForcedBreakProducesRealMultiplePdfPagesIncludingSkippedParityPage(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>'
                . '@page { size:300px 200px; margin:20px; }'
                . 'p { margin:0; height:40px; }'
                . '#second { break-before:right; }'
                . '</style><p>one</p><p id="second">two</p>',
            'viewportWidth' => 300,
            'viewportHeight' => 200,
        ]);

        self::assertStringContainsString('/Count 3', $pdf);
        self::assertSame(3, substr_count($pdf, '/Type /Page /Parent'));
    }

    public function testUnsupportedNonWinAnsiCharacterFailsExplicitly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not supported by the current WinAnsi PDF text serializer');

        Pagyra::renderHtmlToPdf([
            'html' => '<p>emoji 😀</p>',
            'viewportWidth' => 200,
            'viewportHeight' => 100,
        ]);
    }
}
