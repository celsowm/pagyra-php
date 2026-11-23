<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

/**
 * `media` selects which `@media` blocks are live. It defaults to `print` (a PDF is print),
 * but judicial-document HTML routinely ships `@media print { * { color: black !important } }`
 * ("black prints faster"), which turns every link black. wkhtmltopdf, invoked without
 * `--print-media-type`, renders those documents as `screen` and keeps links blue; `media`
 * lets a caller match that.
 */
final class MediaTypeOptionTest extends TestCase
{
    private const CSS = '<style>@media print { a { color: #ff0000; } }'
        . '@media screen { a { color: #00ff00; } }</style>';

    // Fill color of the text object that paints LINKMARK. Since the per-glyph font fallback
    // (4c046a2) each run is written as segments, and every segment selects its font and word
    // spacing (`Tf`, `Tw`) between the text matrix and the show operator.
    private const LINK_COLOR = '/([0-9.]+ [0-9.]+ [0-9.]+) rg\n1 0 0 1 [0-9. ]+ Tm\n(?:[^\n]* T[fw]\n)*\(LINKMARK\) Tj/';

    private function linkColorRg(string $media): string
    {
        $pdf = Pagyra::renderHtmlToPdf(
            ['html' => self::CSS . '<p>x <a href="https://t.test/">LINKMARK</a> y</p>']
            + ($media === '' ? [] : ['media' => $media]),
        );
        self::assertMatchesRegularExpression(self::LINK_COLOR, $pdf);
        preg_match(self::LINK_COLOR, $pdf, $m);
        return $m[1];
    }

    public function testDefaultsToPrintMedia(): void
    {
        self::assertSame('1 0 0', $this->linkColorRg(''));
    }

    public function testScreenMediaActivatesScreenBlocks(): void
    {
        self::assertSame('0 1 0', $this->linkColorRg('screen'));
    }

    public function testPrintMediaExplicit(): void
    {
        self::assertSame('1 0 0', $this->linkColorRg('print'));
    }

    public function testScreenMediaLeavesUaAnchorBlueWhenPrintForcesBlackOnEverything(): void
    {
        $pdf = Pagyra::renderHtmlToPdf([
            'html' => '<style>@media print { * { color: black !important; } }</style>'
                . '<p>x <a href="https://t.test/">LINKMARK</a> y</p>',
            'media' => 'screen',
        ]);
        preg_match(self::LINK_COLOR, $pdf, $m);
        self::assertSame('0 0 0.933333', $m[1]); // #0000EE
    }

    public function testRejectsUnknownMedia(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pagyra::renderHtmlToPdf(['html' => '<p>x</p>', 'media' => 'braille']);
    }
}
