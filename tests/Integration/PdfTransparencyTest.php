<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class PdfTransparencyTest extends TestCase
{
    public function testBackgroundBorderAndTextAlphaUseDeduplicatedExtGStates(): void
    {
        $html = '<style>@page{size:220px 120px;margin:10px}</style>'
            . '<div style="margin:0;width:80px;height:30px;'
            . 'background-color:rgba(255,0,0,0.5);'
            . 'border-width:2px;border-style:solid;border-color:rgba(0,255,0,0.5);">'
            . '<span style="color:rgba(0,0,255,0.25)">Hi</span>'
            . '</div>'
            . '<p style="margin:0;color:black">opaque</p>';

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => $html,
            'viewportWidth' => 220,
            'viewportHeight' => 120,
        ]);

        self::assertSame(2, substr_count($pdf, '/Type /ExtGState'));
        self::assertStringContainsString('/ca 0.5 /CA 0.5', $pdf);
        self::assertStringContainsString('/ca 0.25 /CA 0.25', $pdf);
        self::assertStringContainsString('/ExtGState << /GS1 ', $pdf);
        self::assertStringContainsString('/GS2 ', $pdf);
        self::assertGreaterThanOrEqual(2, substr_count($pdf, "/GS1 gs\n"));
        self::assertStringContainsString("/GS2 gs\nBT\n", $pdf);
        self::assertStringContainsString("Q\n", $pdf);
        self::assertStringContainsString('(opaque) Tj', $pdf);
    }

    public function testFullyTransparentRgbaFillAndBorderDoNotPaint(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<div style="margin:0;width:40px;height:20px;'
                . 'background-color:rgba(255,0,0,0);'
                . 'border-width:3px;border-style:solid;border-color:rgba(0,0,255,0)"></div>',
            'viewportWidth' => 100,
            'viewportHeight' => 80,
        ]);

        self::assertStringNotContainsString('/Type /ExtGState', $pdf);
        self::assertStringNotContainsString("1 0 0 rg\n", $pdf);
        self::assertStringNotContainsString("0 0 1 rg\n", $pdf);
    }
}
