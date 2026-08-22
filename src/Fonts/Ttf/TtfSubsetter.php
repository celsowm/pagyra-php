<?php

declare(strict_types=1);

namespace Pagyra\Fonts\Ttf;

final class TtfSubsetter
{
    /**
     * Builds an identity-mapped sparse TrueType subset. Original glyph IDs are
     * preserved so PDF CID == GID remains valid. Returns null when the input
     * cannot be safely subsetted; callers should fall back to the full font.
     *
     * @param list<int> $usedGlyphIds
     */
    public function subset(string $binary, array $usedGlyphIds): ?string
    {
        try {
            $tables = $this->directory($binary);
            foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf'] as $required) {
                if (!isset($tables[$required])) return null;
            }

            $head = $this->tableBytes($binary, $tables['head']);
            $hhea = $this->tableBytes($binary, $tables['hhea']);
            $maxp = $this->tableBytes($binary, $tables['maxp']);
            $hmtx = $this->tableBytes($binary, $tables['hmtx']);
            $loca = $this->tableBytes($binary, $tables['loca']);
            $glyf = $this->tableBytes($binary, $tables['glyf']);
            if (strlen($head) < 54 || strlen($hhea) < 36 || strlen($maxp) < 6) return null;

            $numGlyphs = $this->u16($maxp, 4);
            if ($numGlyphs <= 0) return null;
            $indexToLocFormat = $this->i16($head, 50);
            if (!in_array($indexToLocFormat, [0, 1], true)) return null;

            $used = [0 => true];
            foreach ($usedGlyphIds as $gid) {
                if ($gid >= 0 && $gid < $numGlyphs) $used[$gid] = true;
            }
            $this->includeCompositeDependencies($glyf, $loca, $indexToLocFormat, $numGlyphs, $used);
            $glyphIds = array_keys($used);
            sort($glyphIds, SORT_NUMERIC);
            $maxGid = max($glyphIds);

            $newGlyf = '';
            $offsets = [];
            for ($gid = 0; $gid <= $maxGid; $gid++) {
                $offsets[] = strlen($newGlyf);
                if (!isset($used[$gid])) continue;
                [$start, $end] = $this->glyphRange($loca, $indexToLocFormat, $gid, $numGlyphs);
                if ($end <= $start || $start < 0 || $end > strlen($glyf)) continue;
                $newGlyf .= substr($glyf, $start, $end - $start);
            }
            $offsets[] = strlen($newGlyf);

            $newLoca = '';
            foreach ($offsets as $offset) $newLoca .= pack('N', $offset);

            $numberOfHMetrics = $this->u16($hhea, 34);
            if ($numberOfHMetrics <= 0) return null;
            $newHmtx = '';
            for ($gid = 0; $gid <= $maxGid; $gid++) {
                [$advance, $lsb] = $this->horizontalMetric($hmtx, $numberOfHMetrics, $gid);
                $newHmtx .= pack('n', $advance) . pack('n', $lsb & 0xFFFF);
            }

            $newHead = $head;
            $this->setU32($newHead, 8, 0);
            $this->setU16($newHead, 50, 1);

            $newHhea = $hhea;
            $this->setU16($newHhea, 34, $maxGid + 1);

            $maxpLength = min(strlen($maxp), 32);
            $newMaxp = substr($maxp, 0, $maxpLength);
            $this->setU16($newMaxp, 4, $maxGid + 1);

            $newTables = [
                'head' => $newHead,
                'hhea' => $newHhea,
                'maxp' => $newMaxp,
                'hmtx' => $newHmtx,
                'loca' => $newLoca,
                'glyf' => $newGlyf,
                'cmap' => $this->minimalCmap(),
            ];
            if (isset($tables['OS/2'])) $newTables['OS/2'] = $this->tableBytes($binary, $tables['OS/2']);
            if (isset($tables['name'])) $newTables['name'] = $this->tableBytes($binary, $tables['name']);
            if (isset($tables['post'])) $newTables['post'] = $this->tableBytes($binary, $tables['post']);

            ksort($newTables, SORT_STRING);
            $font = $this->buildFont($newTables);
            $headOffset = $this->headOffset($font);
            if ($headOffset === null) return null;
            $adjustment = (0xB1B0AFBA - $this->checksum($font)) & 0xFFFFFFFF;
            $this->setU32($font, $headOffset + 8, $adjustment);
            return $font;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,array{offset:int,length:int}> */
    private function directory(string $binary): array
    {
        if (strlen($binary) < 12 || unpack('N', substr($binary, 0, 4))[1] !== 0x00010000) return [];
        $count = $this->u16($binary, 4);
        if (12 + $count * 16 > strlen($binary)) return [];
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $p = 12 + $i * 16;
            $tag = substr($binary, $p, 4);
            $offset = $this->u32($binary, $p + 8);
            $length = $this->u32($binary, $p + 12);
            if ($offset + $length <= strlen($binary)) $tables[$tag] = ['offset' => $offset, 'length' => $length];
        }
        return $tables;
    }

    /** @param array{offset:int,length:int} $table */
    private function tableBytes(string $binary, array $table): string
    {
        return substr($binary, $table['offset'], $table['length']);
    }

    /** @param array<int,bool> $used */
    private function includeCompositeDependencies(string $glyf, string $loca, int $format, int $numGlyphs, array &$used): void
    {
        $stack = array_keys($used);
        while ($stack !== []) {
            $gid = array_pop($stack);
            [$start, $end] = $this->glyphRange($loca, $format, $gid, $numGlyphs);
            if ($end - $start < 10 || $end > strlen($glyf)) continue;
            if ($this->i16($glyf, $start) >= 0) continue;

            $pos = $start + 10;
            do {
                if ($pos + 4 > $end) break;
                $flags = $this->u16($glyf, $pos);
                $component = $this->u16($glyf, $pos + 2);
                if ($component < $numGlyphs && !isset($used[$component])) {
                    $used[$component] = true;
                    $stack[] = $component;
                }
                $pos += 4;
                $pos += ($flags & 0x0001) !== 0 ? 4 : 2;
                if (($flags & 0x0008) !== 0) $pos += 2;
                elseif (($flags & 0x0040) !== 0) $pos += 4;
                elseif (($flags & 0x0080) !== 0) $pos += 8;
            } while (($flags & 0x0020) !== 0);
        }
    }

    /** @return array{0:int,1:int} */
    private function glyphRange(string $loca, int $format, int $gid, int $numGlyphs): array
    {
        if ($gid < 0 || $gid >= $numGlyphs) return [0, 0];
        if ($format === 0) {
            $p = $gid * 2;
            if ($p + 4 > strlen($loca)) return [0, 0];
            return [$this->u16($loca, $p) * 2, $this->u16($loca, $p + 2) * 2];
        }
        $p = $gid * 4;
        if ($p + 8 > strlen($loca)) return [0, 0];
        return [$this->u32($loca, $p), $this->u32($loca, $p + 4)];
    }

    /** @return array{0:int,1:int} */
    private function horizontalMetric(string $hmtx, int $numberOfHMetrics, int $gid): array
    {
        if ($gid < $numberOfHMetrics) {
            $p = $gid * 4;
            return [$this->u16($hmtx, $p), $this->i16($hmtx, $p + 2)];
        }
        $advance = $this->u16($hmtx, ($numberOfHMetrics - 1) * 4);
        $p = $numberOfHMetrics * 4 + ($gid - $numberOfHMetrics) * 2;
        $lsb = $p + 2 <= strlen($hmtx) ? $this->i16($hmtx, $p) : 0;
        return [$advance, $lsb];
    }

    private function minimalCmap(): string
    {
        return pack('nnnnN', 0, 1, 3, 1, 12)
            . pack('nnnnnnnnnnnn', 4, 24, 0, 2, 2, 0, 0, 0xFFFF, 0, 0xFFFF, 1, 0);
    }

    /** @param array<string,string> $tables */
    private function buildFont(array $tables): string
    {
        $count = count($tables);
        $pow2 = 1;
        $selector = 0;
        while (($pow2 * 2) <= $count) { $pow2 *= 2; $selector++; }
        $searchRange = $pow2 * 16;
        $rangeShift = $count * 16 - $searchRange;
        $header = pack('Nnnnn', 0x00010000, $count, $searchRange, $selector, $rangeShift);
        $directory = '';
        $payload = '';
        $offset = 12 + $count * 16;
        foreach ($tables as $tag => $data) {
            $directory .= str_pad(substr($tag, 0, 4), 4, "\0")
                . pack('N', $this->checksum($data))
                . pack('N', $offset)
                . pack('N', strlen($data));
            $payload .= $data;
            $padding = (4 - (strlen($data) % 4)) % 4;
            if ($padding) $payload .= str_repeat("\0", $padding);
            $offset += strlen($data) + $padding;
        }
        return $header . $directory . $payload;
    }

    private function headOffset(string $font): ?int
    {
        $tables = $this->directory($font);
        return $tables['head']['offset'] ?? null;
    }

    private function checksum(string $data): int
    {
        $padding = (4 - (strlen($data) % 4)) % 4;
        if ($padding) $data .= str_repeat("\0", $padding);
        $sum = 0;
        for ($i = 0; $i < strlen($data); $i += 4) {
            $word = unpack('N', substr($data, $i, 4))[1];
            $sum = ($sum + $word) & 0xFFFFFFFF;
        }
        return $sum;
    }

    private function u16(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) throw new \RuntimeException('Out of bounds u16');
        return unpack('n', substr($data, $offset, 2))[1];
    }

    private function i16(string $data, int $offset): int
    {
        $value = $this->u16($data, $offset);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function u32(string $data, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) throw new \RuntimeException('Out of bounds u32');
        return unpack('N', substr($data, $offset, 4))[1];
    }

    private function setU16(string &$data, int $offset, int $value): void
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) throw new \RuntimeException('Out of bounds setU16');
        $data = substr_replace($data, pack('n', $value & 0xFFFF), $offset, 2);
    }

    private function setU32(string &$data, int $offset, int $value): void
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) throw new \RuntimeException('Out of bounds setU32');
        $data = substr_replace($data, pack('N', $value & 0xFFFFFFFF), $offset, 4);
    }
}
