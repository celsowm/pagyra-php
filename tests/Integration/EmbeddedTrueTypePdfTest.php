<?php

declare(strict_types=1);

namespace Pagyra\Tests\Integration;

use Pagyra\Pagyra;
use PHPUnit\Framework\TestCase;

final class EmbeddedTrueTypePdfTest extends TestCase
{
    public function testCssTrueTypeFontIsEmbeddedAsUnicodeType0Font(): void
    {
        $font = $this->fontFixture();
        $dataUrl = 'data:font/ttf;base64,' . base64_encode($font);
        $html = '<style>'
            . '@font-face{font-family:"FixtureUnicode";src:url("' . $dataUrl . '") format("truetype");}'
            . 'p{font-family:"FixtureUnicode";font-size:10px;margin:0;}'
            . '</style><p>ABΩ</p>';

        $pdf = Pagyra::renderHtmlToPdf([
            'html' => $html,
            'viewportWidth' => 400,
            'viewportHeight' => 300,
        ]);

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/Subtype /Type0', $pdf);
        self::assertStringContainsString('/Subtype /CIDFontType2', $pdf);
        self::assertStringContainsString('/Encoding /Identity-H', $pdf);
        self::assertStringContainsString('/CIDToGIDMap /Identity', $pdf);
        self::assertStringContainsString('/FontFile2 ', $pdf);
        self::assertStringContainsString('/ToUnicode ', $pdf);
        self::assertStringContainsString('<0001> <0041>', $pdf);
        self::assertStringContainsString('<0002> <0042>', $pdf);
        self::assertStringContainsString('<0003> <03A9>', $pdf);
        self::assertStringContainsString('[<0001> 50 <0002> <0003>] TJ', $pdf);
        self::assertStringNotContainsString('/WinAnsiEncoding', $pdf);
    }

    private function fontFixture(): string
    {
        $head = str_repeat("\0", 54);
        $head = substr_replace($head, pack('n', 1000), 18, 2);
        $head = substr_replace($head, pack('n', 0), 36, 2);
        $head = substr_replace($head, pack('n', 0x10000 - 200), 38, 2);
        $head = substr_replace($head, pack('n', 1000), 40, 2);
        $head = substr_replace($head, pack('n', 800), 42, 2);

        $hhea = str_repeat("\0", 36);
        $hhea = substr_replace($hhea, pack('n', 800), 4, 2);
        $hhea = substr_replace($hhea, pack('n', 0x10000 - 200), 6, 2);
        $hhea = substr_replace($hhea, pack('n', 4), 34, 2);

        $maxp = str_repeat("\0", 6);
        $maxp = substr_replace($maxp, pack('n', 4), 4, 2);
        $hmtx = pack('nnnnnnnn', 500, 0, 600, 0, 610, 0, 620, 0);

        $cmap12 = pack('nnNNN', 12, 0, 40, 0, 2)
            . pack('NNN', 65, 66, 1)
            . pack('NNN', 0x03A9, 0x03A9, 3);
        $cmap = pack('nnnnN', 0, 1, 3, 10, 12) . $cmap12;

        $kernSubtable = pack('nnn', 0, 20, 0)
            . pack('nnnn', 1, 0, 0, 0)
            . pack('nnn', 1, 2, 0x10000 - 50);
        $kern = pack('nn', 0, 1) . $kernSubtable;
        $loca = pack('nnnn', 0, 0, 0, 0);
        $glyf = '';

        $tables = [
            'head' => $head,
            'hhea' => $hhea,
            'maxp' => $maxp,
            'hmtx' => $hmtx,
            'cmap' => $cmap,
            'kern' => $kern,
            'loca' => $loca,
            'glyf' => $glyf,
        ];

        $headerSize = 12 + count($tables) * 16;
        $offset = $headerSize;
        $directory = '';
        $payload = '';
        foreach ($tables as $tag => $data) {
            $directory .= pack('a4NNN', $tag, 0, $offset, strlen($data));
            $payload .= $data;
            $offset += strlen($data);
        }

        return pack('Nnnnn', 0x00010000, count($tables), 0, 0, 0) . $directory . $payload;
    }
}
