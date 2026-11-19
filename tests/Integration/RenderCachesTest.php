<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Fonts\FontRegistry;
use Pagyra\Fonts\GlyphTextMetrics;
use Pagyra\Fonts\Ttf\TtfParser;
use Pagyra\Fonts\Ttf\TtfSubsetter;
use Pagyra\Pagyra;
use Pagyra\Style\ComputedStyle;
use PHPUnit\Framework\TestCase;

/**
 * What a render keeps for the next one, and what it no longer redoes within itself: parsed fonts
 * are shared by every registry of the process, a subset font drops the glyph names it never
 * needed, and a text measurement is computed once per text and style.
 */
final class RenderCachesTest extends TestCase
{
    private const FONT = __DIR__ . '/../../assets/fonts/dejavu/DejaVuSans.ttf';

    public function testRegistriesShareTheParsedFontOfTheSameBytes(): void
    {
        FontRegistry::clearCache();
        $first = new FontRegistry();
        $first->registerFile('Doc', self::FONT);
        $second = new FontRegistry();
        $second->registerData('Outra', (string) file_get_contents(self::FONT), 700);

        self::assertSame($first->resolve('Doc'), $second->resolve('Outra', 700));

        FontRegistry::clearCache();
        $third = new FontRegistry();
        $third->registerFile('Doc', self::FONT);
        self::assertNotSame($first->resolve('Doc'), $third->resolve('Doc'));
        self::assertEquals($first->resolve('Doc'), $third->resolve('Doc'));
    }

    public function testSubsetKeepsMetricsAndDropsTheGlyphNames(): void
    {
        $binary = (string) file_get_contents(self::FONT);
        $original = (new TtfParser())->parse($binary);
        $glyphs = array_map(static fn(int $cp): int => $original->glyphId($cp), [65, 66, 97, 0xE9, 0x20AC]);

        $subset = (new TtfSubsetter())->subset($binary, $glyphs);
        self::assertNotNull($subset);
        $parsed = (new TtfParser())->parse($subset);
        foreach ($glyphs as $gid) {
            self::assertSame($original->advanceWidth($gid), $parsed->advanceWidth($gid));
        }

        $tables = [];
        $count = unpack('n', substr($subset, 4, 2))[1];
        for ($i = 0; $i < $count; $i++) {
            $record = substr($subset, 12 + $i * 16, 16);
            $tables[substr($record, 0, 4)] = unpack('Nchecksum/Noffset/Nlength', substr($record, 4));
        }
        self::assertSame(32, $tables['post']['length']);
        self::assertSame(0x00030000, unpack('N', substr($subset, $tables['post']['offset'], 4))[1]);
        self::assertLessThan(strlen($binary) / 4, strlen($subset));

        $sum = 0;
        foreach (unpack('N*', str_pad($subset, (int) ceil(strlen($subset) / 4) * 4, "\0")) as $word) {
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }
        self::assertSame(0xB1B0AFBA, $sum, 'the whole-font checksum still balances');
    }

    public function testTextMeasurementIsReusedOnlyForTheSameStyle(): void
    {
        $registry = new FontRegistry();
        $registry->registerFile('Doc', self::FONT);
        $metrics = new GlyphTextMetrics($registry);
        $style = new ComputedStyle(['font-family' => 'Doc']);

        $first = $metrics->measure('Umsatzsteuer 19 %', $style, 12.0);
        self::assertSame($first, $metrics->measure('Umsatzsteuer 19 %', new ComputedStyle(['font-family' => 'Doc']), 12.0));
        self::assertGreaterThan($first->inlineSize, $metrics->measure('Umsatzsteuer 19 %', new ComputedStyle(['font-family' => 'Doc', 'letter-spacing' => '1px']), 12.0)->inlineSize);
        self::assertGreaterThan($first->inlineSize, $metrics->measure('Umsatzsteuer 19 %', $style, 13.0)->inlineSize);
    }

    public function testRepeatedRendersProduceTheSamePdf(): void
    {
        $html = '<style>@font-face{font-family:Doc;src:url(' . realpath(self::FONT) . ')}body{font-family:Doc}</style>'
            . '<p>Rechnung € 1.083,00 — Ωmega</p><p style="font-family:Helvetica">Base14 e DejaVu: ∑</p>';
        FontRegistry::clearCache();
        $cold = Pagyra::renderHtmlToPdf(['html' => $html]);
        $warm = Pagyra::renderHtmlToPdf(['html' => $html]);

        self::assertSame($cold, $warm);
    }
}
