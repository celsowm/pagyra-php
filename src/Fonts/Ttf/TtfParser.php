<?php

declare(strict_types=1);

namespace Pagyra\Fonts\Ttf;

final class TtfParser
{
    /** @var array<string,array{offset:int,length:int}> */
    private array $tables = [];
    private BinaryReader $reader;

    public function parse(string $binary): TtfFontMetrics
    {
        $this->reader = new BinaryReader($binary);
        $this->parseDirectory();

        $head = $this->table('head');
        $hhea = $this->table('hhea');
        $maxp = $this->table('maxp');
        $hmtx = $this->table('hmtx');
        $cmap = $this->table('cmap');

        $unitsPerEm = $this->reader->u16($head['offset'] + 18);
        $ascent = $this->reader->i16($hhea['offset'] + 4);
        $descent = $this->reader->i16($hhea['offset'] + 6);
        $lineGap = $this->reader->i16($hhea['offset'] + 8);
        $numberOfHMetrics = $this->reader->u16($hhea['offset'] + 34);
        $numGlyphs = $this->reader->u16($maxp['offset'] + 4);

        $advanceWidths = $this->parseHmtx($hmtx, min($numberOfHMetrics, $numGlyphs), $numGlyphs);
        $mapping = $this->parseCmap($cmap);
        $kerning = $this->parseKern();

        return new TtfFontMetrics($unitsPerEm, $ascent, $descent, $lineGap, $advanceWidths, $mapping, $kerning);
    }

    public function parseFile(string $path): TtfFontMetrics
    {
        $binary = @file_get_contents($path);
        if ($binary === false) {
            throw new \RuntimeException("Unable to read font: {$path}");
        }
        return $this->parse($binary);
    }

    private function parseDirectory(): void
    {
        if ($this->reader->length() < 12) throw new \RuntimeException('Truncated font header');
        $numTables = $this->reader->u16(4);
        if (12 + $numTables * 16 > $this->reader->length()) throw new \RuntimeException('Truncated font table directory');
        $this->tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $pos = 12 + $i * 16;
            $tag = $this->reader->tag($pos);
            $offset = $this->reader->u32($pos + 8);
            $length = $this->reader->u32($pos + 12);
            if ($offset + $length <= $this->reader->length()) {
                $this->tables[$tag] = ['offset' => $offset, 'length' => $length];
            }
        }
    }

    /** @return array{offset:int,length:int} */
    private function table(string $tag): array
    {
        return $this->tables[$tag] ?? throw new \RuntimeException("Missing {$tag} table");
    }

    /** @return array<int,int> */
    private function parseHmtx(array $table, int $numberOfHMetrics, int $numGlyphs): array
    {
        $widths = [];
        $lastAdvance = 0;
        for ($gid = 0; $gid < $numberOfHMetrics; $gid++) {
            $lastAdvance = $this->reader->u16($table['offset'] + $gid * 4);
            $widths[$gid] = $lastAdvance;
        }
        for ($gid = $numberOfHMetrics; $gid < $numGlyphs; $gid++) $widths[$gid] = $lastAdvance;
        return $widths;
    }

    /** @return array<int,int> */
    private function parseCmap(array $table): array
    {
        $base = $table['offset'];
        $count = $this->reader->u16($base + 2);
        $best = null;
        $bestFormat = 0;
        for ($i = 0; $i < $count; $i++) {
            $record = $base + 4 + $i * 8;
            $platform = $this->reader->u16($record);
            $encoding = $this->reader->u16($record + 2);
            $offset = $base + $this->reader->u32($record + 4);
            if ($offset + 2 > $base + $table['length']) continue;
            $format = $this->reader->u16($offset);
            $unicode = $platform === 0 || ($platform === 3 && in_array($encoding, [1, 10], true));
            if ($unicode && in_array($format, [4, 12], true) && $format >= $bestFormat) {
                $best = $offset;
                $bestFormat = $format;
            }
        }
        if ($best === null) return [];
        return $bestFormat === 12 ? $this->parseCmap12($best) : $this->parseCmap4($best);
    }

    /** @return array<int,int> */
    private function parseCmap12(int $offset): array
    {
        $groups = $this->reader->u32($offset + 12);
        $map = [];
        for ($i = 0; $i < $groups; $i++) {
            $p = $offset + 16 + $i * 12;
            $start = $this->reader->u32($p);
            $end = $this->reader->u32($p + 4);
            $startGlyph = $this->reader->u32($p + 8);
            for ($cp = $start; $cp <= $end; $cp++) $map[$cp] = $startGlyph + ($cp - $start);
        }
        return $map;
    }

    /** @return array<int,int> */
    private function parseCmap4(int $offset): array
    {
        $segCount = intdiv($this->reader->u16($offset + 6), 2);
        $endStart = $offset + 14;
        $startStart = $endStart + $segCount * 2 + 2;
        $deltaStart = $startStart + $segCount * 2;
        $rangeStart = $deltaStart + $segCount * 2;
        $map = [];
        for ($i = 0; $i < $segCount; $i++) {
            $end = $this->reader->u16($endStart + $i * 2);
            $start = $this->reader->u16($startStart + $i * 2);
            $delta = $this->reader->i16($deltaStart + $i * 2);
            $range = $this->reader->u16($rangeStart + $i * 2);
            if ($start === 0xFFFF && $end === 0xFFFF) continue;
            for ($cp = $start; $cp <= $end; $cp++) {
                if ($range === 0) {
                    $gid = ($cp + $delta) & 0xFFFF;
                } else {
                    $rangePos = $rangeStart + $i * 2;
                    $glyphPos = $rangePos + $range + 2 * ($cp - $start);
                    $gid = $this->reader->u16($glyphPos);
                    if ($gid !== 0) $gid = ($gid + $delta) & 0xFFFF;
                }
                if ($gid !== 0) $map[$cp] = $gid;
            }
        }
        return $map;
    }

    /** @return array<int,array<int,int>> */
    private function parseKern(): array
    {
        if (!isset($this->tables['kern'])) return [];
        $table = $this->tables['kern'];
        $base = $table['offset'];
        if ($table['length'] < 4) return [];
        $nTables = $this->reader->u16($base + 2);
        $cursor = $base + 4;
        $result = [];
        for ($i = 0; $i < $nTables; $i++) {
            if ($cursor + 6 > $base + $table['length']) break;
            $length = $this->reader->u16($cursor + 2);
            $coverage = $this->reader->u16($cursor + 4);
            $format = $coverage >> 8;
            if ($format === 0 && $length >= 14) {
                $pairCount = $this->reader->u16($cursor + 6);
                $pair = $cursor + 14;
                for ($p = 0; $p < $pairCount && $pair + 6 <= $cursor + $length; $p++, $pair += 6) {
                    $left = $this->reader->u16($pair);
                    $right = $this->reader->u16($pair + 2);
                    $value = $this->reader->i16($pair + 4);
                    if ($value !== 0) $result[$left][$right] = $value;
                }
            }
            if ($length <= 0) break;
            $cursor += $length;
        }
        return $result;
    }
}
