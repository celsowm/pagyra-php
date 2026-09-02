<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * When no `@font-face` covers the requested family, the Base14 fallback must read the whole
 * `font-family` stack, not just its first name. Every eproc / TJRJ document ships
 * `font-family: "Calibri, sans-serif"` (or the unquoted list); Calibri is not embedded here,
 * so the trailing generic is what decides the face. Picking the bucket from the first name
 * alone drew those documents in Times while the width table — which does fall through — had
 * measured them as Helvetica, so justified lines were spaced for the wrong font and never
 * reached the margin.
 */
final class Base14FallbackWalksFontStackTest extends TestCase
{
    private function pdf(string $fontFamily): string
    {
        return Pagyra::renderHtmlToPdf([
            'html' => '<p style="font-family:' . $fontFamily . '">Sistema Unico de Saude</p>',
        ]);
    }

    public function testUnquotedCalibriListFallsThroughToHelvetica(): void
    {
        $pdf = $this->pdf('Calibri, sans-serif');

        self::assertStringContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringNotContainsString('/BaseFont /Times-Roman', $pdf);
    }

    public function testWholeListQuotedAsOneStringStillFallsThrough(): void
    {
        // Malformed but common in the wild: the entire list sits inside one pair of quotes.
        $pdf = $this->pdf('&quot;Calibri, sans-serif&quot;');

        self::assertStringContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringNotContainsString('/BaseFont /Times-Roman', $pdf);
    }

    public function testSerifGenericStillResolvesToTimes(): void
    {
        $pdf = $this->pdf('Georgia, serif');

        self::assertStringContainsString('/BaseFont /Times-Roman', $pdf);
        self::assertStringNotContainsString('/BaseFont /Helvetica', $pdf);
    }

    public function testUnknownFamilyWithNoGenericStillDefaultsToTimes(): void
    {
        $pdf = $this->pdf('Calibri');

        self::assertStringContainsString('/BaseFont /Times-Roman', $pdf);
    }

    public function testFirstConcreteGenericWins(): void
    {
        $pdf = $this->pdf('Consolas, monospace, sans-serif');

        self::assertStringContainsString('/BaseFont /Courier', $pdf);
        self::assertStringNotContainsString('/BaseFont /Helvetica', $pdf);
    }
}
