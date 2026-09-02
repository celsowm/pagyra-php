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
        // fontFixture() builds hmtx as `500 + gid * 10`, so the retained gids 0..2 measure
        // 500/510/520. The previous 600/610 never matched the fixture this test itself writes.
        self::assertSame(500, $metrics->advanceWidth(0));
        self::assertSame(510, $metrics->advanceWidth(1));
        self::assertSame(520, $metrics->advanceWidth(2));
    }

    public function testCompositeGlyphKeepsReferencedComponent(): void
    {
        $font = $this->compositeFixture();
        $subset = (new TtfSubsetter())->subset($font, [2]);

        self::assertNotNull($subset);
        $tables = $this->directory($subset);
        $loca = substr($subset, $tables['loca']['offset'], $tables['loca']['length']);
        $offset1 = unpack('N', substr($loca, 4, 4))[1];
        $offset2 = unpack('N', substr($loca, 8, 4))[1];
        self::assertGreaterThan($offset1, $offset2, 'gid 1 component must remain present in sparse subset');
    }

    public function testFallsBackWithNullWhenRequiredTablesAreMissing(): void
    {
        self::assertNull((new TtfSubsetter())->subset("\x00\x01\x00\x00" . str_repeat("\0", 20), [1]));
    }

    private function fontFixture(): string
    {
        return $this->buildFixture([
            pack('n', 0) . str_repeat("\0", 8),
            pack('n', 0) . str_repeat("\x11", 8),
            pack('n', 0) . str_repeat("\x22", 8),
            pack('n', 0) . str_repeat("\x33", 8),
        ]);
    }

    private function compositeFixture(): string
    {
        $component = pack('n', 0) . str_repeat("\x11", 8);
        $composite = pack('n', 0xFFFF) . str_repeat("\0", 8)
            . pack('nncc', 0, 1, 0, 0);
        return $this->buildFixture([
            pack('n', 0) . str_repeat("\0", 8),
            $component,
            $composite,
            pack('n', 0) . str_repeat("\x33", 8),
        ]);
    }

    /** @param list<string> $glyphs */
    private function buildFixture(array $glyphs): string
    {
        $head = str_repeat("\0", 54);
        $head = substr_replace($head, pack('n', 1000), 18, 2);
        $head = substr_replace($head, pack('n', 1), 50, 2);

        $hhea = str_repeat("\0", 36);
        $hhea = substr_replace($hhea, pack('n', 800), 4, 2);
        $hhea = substr_replace($hhea, pack('n', 0x10000 - 200), 6, 2);
        $hhea = substr_replace($hhea, pack('n', count($glyphs)), 34, 2);

        $maxp = str_repeat("\0", 32);
        $maxp = substr_replace($maxp, pack('n', count($glyphs)), 4, 2);
        $hmtx = '';
        foreach ($glyphs as $gid => $_) $hmtx .= pack('nn', 500 + $gid * 10, 0);

        $glyf = '';
        $offsets = [0];
        foreach ($glyphs as $glyph) {
            $glyf .= $glyph;
            $offsets[] = strlen($glyf);
        }
        $loca = '';
        foreach ($offsets as $offset) $loca .= pack('N', $offset);

        $cmap12 = pack('nnNNN', 12, 0, 28, 0, 1) . pack('NNN', 65, 68, 0);
        $cmap = pack('nnnnN', 0, 1, 3, 10, 12) . $cmap12;
        $tables = [
            'head' => $head,
            'hhea' => $hhea,
            'maxp' => $maxp,
            'hmtx' => $hmtx,
            'loca' => $loca,
            'glyf' => $glyf,
            'cmap' => $cmap,
            'name' => pack('nnn', 0, 0, 6),
            'post' => pack('N', 0x00030000) . str_repeat("\0", 28),
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

    /** @return array<string,array{offset:int,length:int}> */
    private function directory(string $font): array
    {
        $count = unpack('n', substr($font, 4, 2))[1];
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $p = 12 + $i * 16;
            $tag = substr($font, $p, 4);
            $tables[$tag] = [
                'offset' => unpack('N', substr($font, $p + 8, 4))[1],
                'length' => unpack('N', substr($font, $p + 12, 4))[1],
            ];
        }
        return $tables;
    }
}
