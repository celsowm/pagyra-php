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
        $bbox = [
            'xMin' => $this->reader->i16($head['offset'] + 36),
            'yMin' => $this->reader->i16($head['offset'] + 38),
            'xMax' => $this->reader->i16($head['offset'] + 40),
            'yMax' => $this->reader->i16($head['offset'] + 42),
        ];
        $ascent = $this->reader->i16($hhea['offset'] + 4);
        $descent = $this->reader->i16($hhea['offset'] + 6);
        $lineGap = $this->reader->i16($hhea['offset'] + 8);
        $numberOfHMetrics = $this->reader->u16($hhea['offset'] + 34);
        $numGlyphs = $this->reader->u16($maxp['offset'] + 4);

        $advanceWidths = $this->parseHmtx($hmtx, min($numberOfHMetrics, $numGlyphs), $numGlyphs);
        $mapping = $this->parseCmap($cmap);
        $gpos = $this->parseGpos();
        $hasGposKerning = $gpos['pairs'] !== [] || $gpos['classes'] !== [];
        $kerning = $hasGposKerning ? $gpos['pairs'] : $this->parseKern();

        return new TtfFontMetrics(
            $unitsPerEm,
            $ascent,
            $descent,
            $lineGap,
            $advanceWidths,
            $mapping,
            $kerning,
            $bbox,
            $hasGposKerning ? $gpos['classes'] : [],
        );
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

    /**
     * OpenType GPOS Pair Adjustment for the default kerning feature.
     *
     * Format 1 contributes explicit glyph pairs. Format 2 stays class-based so a font with
     * thousands of glyphs does not explode into a dense pair map. If any usable GPOS kern lookup
     * exists, it supersedes the legacy 'kern' table instead of double-applying the same design
     * adjustment twice.
     *
     * @return array{pairs:array<int,array<int,int>>,classes:list<array{coverage:array<int,bool>,class1:array<int,int>,class2:array<int,int>,values:array<int,array<int,int>>}>}
     */
    private function parseGpos(): array
    {
        $empty = ['pairs' => [], 'classes' => []];
        if (!isset($this->tables['GPOS'])) return $empty;

        $table = $this->tables['GPOS'];
        $base = $table['offset'];
        $end = $base + $table['length'];
        if ($table['length'] < 10) return $empty;

        $featureList = $base + $this->reader->u16($base + 6);
        $lookupList = $base + $this->reader->u16($base + 8);
        if ($featureList < $base || $featureList + 2 > $end || $lookupList < $base || $lookupList + 2 > $end) {
            return $empty;
        }

        $lookupIndexes = [];
        $featureCount = $this->reader->u16($featureList);
        for ($i = 0; $i < $featureCount; $i++) {
            $record = $featureList + 2 + $i * 6;
            if ($record + 6 > $end) break;
            if ($this->reader->tag($record) !== 'kern') continue;
            $feature = $featureList + $this->reader->u16($record + 4);
            if ($feature + 4 > $end) continue;
            $count = $this->reader->u16($feature + 2);
            for ($j = 0; $j < $count; $j++) {
                $p = $feature + 4 + $j * 2;
                if ($p + 2 > $end) break;
                $lookupIndexes[$this->reader->u16($p)] = true;
            }
        }
        if ($lookupIndexes === []) return $empty;

        $lookupCount = $this->reader->u16($lookupList);
        $pairs = [];
        $classes = [];

        foreach (array_keys($lookupIndexes) as $lookupIndex) {
            if ($lookupIndex < 0 || $lookupIndex >= $lookupCount) continue;
            $offsetPos = $lookupList + 2 + $lookupIndex * 2;
            if ($offsetPos + 2 > $end) continue;
            $lookup = $lookupList + $this->reader->u16($offsetPos);
            if ($lookup + 6 > $end) continue;

            $lookupType = $this->reader->u16($lookup);
            $subtableCount = $this->reader->u16($lookup + 4);
            for ($s = 0; $s < $subtableCount; $s++) {
                $subOffsetPos = $lookup + 6 + $s * 2;
                if ($subOffsetPos + 2 > $end) break;
                $subtable = $lookup + $this->reader->u16($subOffsetPos);
                $type = $lookupType;

                // Extension Positioning Lookup: unwrap a type-2 PairPos subtable.
                if ($type === 9 && $subtable + 8 <= $end && $this->reader->u16($subtable) === 1) {
                    $type = $this->reader->u16($subtable + 2);
                    $subtable += $this->reader->u32($subtable + 4);
                }
                if ($type !== 2 || $subtable + 2 > $end) continue;

                $parsed = $this->parsePairPos($subtable, $end);
                foreach ($parsed['pairs'] as $left => $rights) {
                    foreach ($rights as $right => $value) {
                        $pairs[$left][$right] = ($pairs[$left][$right] ?? 0) + $value;
                    }
                }
                array_push($classes, ...$parsed['classes']);
            }
        }

        return ['pairs' => $pairs, 'classes' => $classes];
    }

    /**
     * @return array{pairs:array<int,array<int,int>>,classes:list<array{coverage:array<int,bool>,class1:array<int,int>,class2:array<int,int>,values:array<int,array<int,int>>}>}
     */
    private function parsePairPos(int $base, int $tableEnd): array
    {
        $empty = ['pairs' => [], 'classes' => []];
        if ($base + 10 > $tableEnd) return $empty;

        $format = $this->reader->u16($base);
        $coverageOffset = $this->reader->u16($base + 2);
        $valueFormat1 = $this->reader->u16($base + 4);
        $valueFormat2 = $this->reader->u16($base + 6);
        $coverage = $this->parseCoverage($base + $coverageOffset, $tableEnd);
        if ($coverage === []) return $empty;

        if ($format === 1) {
            $pairSetCount = $this->reader->u16($base + 8);
            if ($base + 10 + $pairSetCount * 2 > $tableEnd) return $empty;
            $pairs = [];
            $record1Size = $this->valueRecordSize($valueFormat1);
            $record2Size = $this->valueRecordSize($valueFormat2);

            for ($i = 0; $i < $pairSetCount && isset($coverage[$i]); $i++) {
                $set = $base + $this->reader->u16($base + 10 + $i * 2);
                if ($set + 2 > $tableEnd) continue;
                $count = $this->reader->u16($set);
                $cursor = $set + 2;
                for ($p = 0; $p < $count; $p++) {
                    if ($cursor + 2 + $record1Size + $record2Size > $tableEnd) break;
                    $right = $this->reader->u16($cursor);
                    $cursor += 2;
                    $value = $this->xAdvance($cursor, $valueFormat1);
                    $cursor += $record1Size + $record2Size;
                    if ($value !== 0) $pairs[$coverage[$i]][$right] = $value;
                }
            }
            return ['pairs' => $pairs, 'classes' => []];
        }

        if ($format !== 2 || $base + 16 > $tableEnd) return $empty;
        $class1 = $this->parseClassDef($base + $this->reader->u16($base + 8), $tableEnd);
        $class2 = $this->parseClassDef($base + $this->reader->u16($base + 10), $tableEnd);
        $class1Count = $this->reader->u16($base + 12);
        $class2Count = $this->reader->u16($base + 14);
        $record1Size = $this->valueRecordSize($valueFormat1);
        $record2Size = $this->valueRecordSize($valueFormat2);
        $cursor = $base + 16;
        $values = [];

        for ($a = 0; $a < $class1Count; $a++) {
            for ($b = 0; $b < $class2Count; $b++) {
                if ($cursor + $record1Size + $record2Size > $tableEnd) break 2;
                $value = $this->xAdvance($cursor, $valueFormat1);
                $cursor += $record1Size + $record2Size;
                if ($value !== 0) $values[$a][$b] = $value;
            }
        }

        if ($values === []) return $empty;
        return [
            'pairs' => [],
            'classes' => [[
                'coverage' => array_fill_keys($coverage, true),
                'class1' => $class1,
                'class2' => $class2,
                'values' => $values,
            ]],
        ];
    }

    /** @return list<int> glyph IDs in coverage-index order */
    private function parseCoverage(int $base, int $tableEnd): array
    {
        if ($base < 0 || $base + 4 > $tableEnd) return [];
        $format = $this->reader->u16($base);
        $count = $this->reader->u16($base + 2);
        $glyphs = [];

        if ($format === 1) {
            if ($base + 4 + $count * 2 > $tableEnd) return [];
            for ($i = 0; $i < $count; $i++) $glyphs[] = $this->reader->u16($base + 4 + $i * 2);
            return $glyphs;
        }

        if ($format !== 2 || $base + 4 + $count * 6 > $tableEnd) return [];
        for ($i = 0; $i < $count; $i++) {
            $p = $base + 4 + $i * 6;
            $start = $this->reader->u16($p);
            $end = $this->reader->u16($p + 2);
            $coverageIndex = $this->reader->u16($p + 4);
            for ($glyph = $start; $glyph <= $end; $glyph++) {
                $glyphs[$coverageIndex + $glyph - $start] = $glyph;
            }
        }
        ksort($glyphs, SORT_NUMERIC);
        return array_values($glyphs);
    }

    /** @return array<int,int> glyph ID => class, absent glyphs implicitly class 0 */
    private function parseClassDef(int $base, int $tableEnd): array
    {
        if ($base < 0 || $base + 4 > $tableEnd) return [];
        $format = $this->reader->u16($base);
        $classes = [];

        if ($format === 1) {
            $startGlyph = $this->reader->u16($base + 2);
            $count = $this->reader->u16($base + 4);
            if ($base + 6 + $count * 2 > $tableEnd) return [];
            for ($i = 0; $i < $count; $i++) {
                $class = $this->reader->u16($base + 6 + $i * 2);
                if ($class !== 0) $classes[$startGlyph + $i] = $class;
            }
            return $classes;
        }

        if ($format !== 2) return [];
        $count = $this->reader->u16($base + 2);
        if ($base + 4 + $count * 6 > $tableEnd) return [];
        for ($i = 0; $i < $count; $i++) {
            $p = $base + 4 + $i * 6;
            $start = $this->reader->u16($p);
            $end = $this->reader->u16($p + 2);
            $class = $this->reader->u16($p + 4);
            if ($class === 0) continue;
            for ($glyph = $start; $glyph <= $end; $glyph++) $classes[$glyph] = $class;
        }
        return $classes;
    }

    private function valueRecordSize(int $format): int
    {
        $size = 0;
        for ($bit = 0; $bit < 16; $bit++) {
            if (($format & (1 << $bit)) !== 0) $size += 2;
        }
        return $size;
    }

    private function xAdvance(int $base, int $format): int
    {
        $cursor = $base;
        for ($bit = 0; $bit < 16; $bit++) {
            $mask = 1 << $bit;
            if (($format & $mask) === 0) continue;
            if ($mask === 0x0004) return $this->reader->i16($cursor);
            $cursor += 2;
        }
        return 0;
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
