<?php

declare(strict_types=1);

namespace Pagyra\Tests\Unit\Fonts;

use Pagyra\Fonts\Ttf\TtfParser;
use Pagyra\Fonts\Ttf\TtfSubsetter;
use PHPUnit\Framework\TestCase;

final class TtfSubsetterTest extends TestCase
{
    public function testBuildsParseableSparseIdentitySubset(): void
    {
        $font = $this->fontFixture();
        $subset = (new TtfSubsetter())->subset($font, [2]);

        self::assertNotNull($subset);
        self::assertLessThan(strlen($font), strlen($subset));
        self::assertSame("\x00\x01\x00\x00", substr($subset, 0, 4));

        $metrics = (new TtfParser())->parse($subset);
        self::assertSame(1000, $metrics->unitsPerEm);
        self::assertSame(3, count($metrics->advanceWidths));
        self::assertSame(500, $metrics->advanceWidth(0));
        self::assertSame(600, $metrics->advanceWidth(1));
        self::assertSame(610, $metrics->advanceWidth(2));
    }

    public function testFallsBackWithNullWhenRequiredTablesAreMissing(): void
    {
        self::assertNull((new TtfSubsetter())->subset("\x00\x01\x00\x00" . str_repeat("\0", 20), [1]));
    }

    private function fontFixture(): string
    {
        $head = str_repeat("\0", 54);
        $head = substr_replace($head, pack('n', 1000), 18, 2);
        $head = substr_replace($head, pack('n', 1), 50, 2);

        $hhea = str_repeat("\0", 36);
        $hhea = substr_replace($hhea, pack('n', 800), 4, 2);
        $hhea = substr_replace($hhea, pack('n', 0x10000 - 200), 6, 2);
        $hhea = substr_replace($hhea, pack('n', 4), 34, 2);

        $maxp = str_repeat("\0", 32);
        $maxp = substr_replace($maxp, pack('n', 4), 4, 2);
        $hmtx = pack('nnnnnnnn', 500, 0, 600, 0, 610, 0, 620, 0);

        $glyph0 = pack('n', 0) . str_repeat("\0", 8);
        $glyph1 = pack('n', 0) . str_repeat("\x11", 8);
        $glyph2 = pack('n', 0) . str_repeat("\x22", 8);
        $glyph3 = pack('n', 0) . str_repeat("\x33", 8);
        $glyf = $glyph0 . $glyph1 . $glyph2 . $glyph3;
        $loca = pack('NNNNN', 0, 10, 20, 30, 40);

        $cmap12 = pack('nnNNN', 12, 0, 28, 0, 1) . pack('NNN', 65, 68, 0);
        $cmap = pack('nnnnN', 0, 1, 3, 10, 12) . $cmap12;

        $name = pack('nnn', 0, 0, 6);
        $post = pack('N', 0x00030000) . str_repeat("\0", 28);

        $tables = [
            'head' => $head,
            'hhea' => $hhea,
            'maxp' => $maxp,
            'hmtx' => $hmtx,
            'loca' => $loca,
            'glyf' => $glyf,
            'cmap' => $cmap,
            'name' => $name,
            'post' => $post,
        ];
        ksort($tables, SORT_STRING);

        $count = count($tables);
        $offset = 12 + $count * 16;
        $directory = '';
        $payload = '';
        foreach ($tables as $tag => $data) {
            $directory .= pack('a4NNN', $tag, 0, $offset, strlen($data));
            $payload .= $data;
            $padding = (4 - (strlen($data) % 4)) % 4;
            if ($padding) $payload .= str_repeat("\0", $padding);
            $offset += strlen($data) + $padding;
        }

        return pack('Nnnnn', 0x00010000, $count, 0, 0, 0) . $directory . $payload;
    }
}
