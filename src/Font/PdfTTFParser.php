<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font;
use Celsowm\PagyraPhp\Font\PdfByteReader;


final class PdfTTFParser
{
    private string $raw;
    private array $table;

    public function __construct(string $ttfData)
    {
        $this->raw = $ttfData;
        $this->table = $this->parseDirectory();
    }

    private function parseDirectory(): array
    {
        $raw = $this->raw;
        $numTables = PdfByteReader::be16($raw, 4);
        $pos = 12;
        $table = [];
        for ($i = 0; $i < $numTables; $i++) {
            $tag = substr($raw, $pos, 4);
            $table[$tag] = [
                'off' => PdfByteReader::beU32($raw, $pos + 8),
                'len' => PdfByteReader::beU32($raw, $pos + 12)
            ];
            $pos += 16;
        }
        return $table;
    }

    private function slice(string $tag): string
    {
        if (!isset($this->table[$tag])) {
            throw new \RuntimeException("Tabela TTF '{$tag}' ausente.");
        }
        return substr($this->raw, $this->table[$tag]['off'], $this->table[$tag]['len']);
    }

    public function parseHead(): array
    {
        $s = $this->slice('head');
        return [
            'unitsPerEm' => PdfByteReader::be16($s, 18),
            'bbox' => [
                PdfByteReader::beS16($s, 36),
                PdfByteReader::beS16($s, 38),
                PdfByteReader::beS16($s, 40),
                PdfByteReader::beS16($s, 42)
            ]
        ];
    }

    public function parseHhea(): array
    {
        $s = $this->slice('hhea');
        return [
            'ascent' => PdfByteReader::beS16($s, 4),
            'descent' => PdfByteReader::beS16($s, 6),
            'numberOfHMetrics' => PdfByteReader::be16($s, 34)
        ];
    }

    public function parseMaxp(): array
    {
        $s = $this->slice('maxp');
        return ['numGlyphs' => PdfByteReader::be16($s, 4)];
    }

    public function parseHmtx(int $numMetrics, int $numGlyphs): array
    {
        $s = $this->slice('hmtx');
        $adv = [];
        $pos = 0;
        for ($i = 0; $i < $numMetrics; $i++) {
            $adv[$i] = PdfByteReader::be16($s, $pos);
            $pos += 4;
        }
        for ($i = $numMetrics; $i < $numGlyphs; $i++) {
            $adv[$i] = $adv[$numMetrics - 1] ?? 0;
        }
        return ['adv' => $adv];
    }

    private function decodeNameString(string $raw, int $platformId, int $encodingId): string
    {
        $value = '';
        if ($platformId === 0 || $platformId === 3 || ($platformId === 2 && $encodingId === 1)) {
            $decoded = @iconv('UTF-16BE', 'UTF-8', $raw);
            if (is_string($decoded)) {
                $value = $decoded;
            }
        }
        if ($value === '') {
            $value = $raw;
        }
        $value = trim($value);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        return is_string($clean) ? $clean : '';
    }

    /**
     * @return array<int, array{platformId:int, encodingId:int, languageId:int, nameId:int, value:string}>
     */
    public function parseNameRecords(): array
    {
        $s = $this->slice('name');
        $count = PdfByteReader::be16($s, 2);
        $strOff = PdfByteReader::be16($s, 4);
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $rec = 6 + ($i * 12);
            $platformId = PdfByteReader::be16($s, $rec);
            $encodingId = PdfByteReader::be16($s, $rec + 2);
            $languageId = PdfByteReader::be16($s, $rec + 4);
            $nameId = PdfByteReader::be16($s, $rec + 6);
            $len = PdfByteReader::be16($s, $rec + 8);
            $off = PdfByteReader::be16($s, $rec + 10);
            $raw = substr($s, $strOff + $off, $len);
            $value = $this->decodeNameString($raw, $platformId, $encodingId);
            if ($value === '') {
                continue;
            }
            $records[] = [
                'platformId' => $platformId,
                'encodingId' => $encodingId,
                'languageId' => $languageId,
                'nameId' => $nameId,
                'value' => $value,
            ];
        }
        return $records;
    }

    public function parseNamePostScript(): ?string
    {
        $s = $this->slice('name');
        $count = PdfByteReader::be16($s, 2);
        $strOff = PdfByteReader::be16($s, 4);
        for ($i = 0; $i < $count; $i++) {
            $rec = 6 + ($i * 12);
            if (PdfByteReader::be16($s, $rec + 6) === 6) {
                $len = PdfByteReader::be16($s, $rec + 8);
                $off = PdfByteReader::be16($s, $rec + 10);
                $name = substr($s, $strOff + $off, $len);
                return PdfByteReader::be16($s, $rec) === 3 ?
                    @iconv('UTF-16BE', 'UTF-8', $name) : $name;
            }
        }
        return null;
    }

    public function parseCmap(): array
    {
        $s = $this->slice('cmap');
        $num = PdfByteReader::be16($s, 2);
        $bestOff = null;
        for ($i = 0; $i < $num; $i++) {
            $rec = 4 + ($i * 8);
            if (
                PdfByteReader::be16($s, $rec) === 3 &&
                in_array(PdfByteReader::be16($s, $rec + 2), [1, 10])
            ) {
                $bestOff = PdfByteReader::beU32($s, $rec + 4);
                if (PdfByteReader::be16($s, $rec + 2) === 10) break;
            }
        }
        if ($bestOff === null) return [];

        $sub = substr($s, $bestOff);
        $format = PdfByteReader::be16($sub, 0);
        return $format === 4 ? $this->parseCmapF4($sub) : ($format === 12 ? $this->parseCmapF12($sub) : []);
    }

    private function parseCmapF4(string $sub): array
    {
        $segCount = PdfByteReader::be16($sub, 6) / 2;
        $map = [];
        $endC = 14;
        $startC = $endC + 2 + $segCount * 2;
        $idDelta = $startC + $segCount * 2;
        $idRange = $idDelta + $segCount * 2;

        for ($i = 0; $i < $segCount; $i++) {
            $end = PdfByteReader::be16($sub, $endC + $i * 2);
            $start = PdfByteReader::be16($sub, $startC + $i * 2);
            $delta = PdfByteReader::beS16($sub, $idDelta + $i * 2);
            $ro = PdfByteReader::be16($sub, $idRange + $i * 2);

            for ($cp = $start; $cp <= $end; $cp++) {
                if ($ro === 0) {
                    $gid = ($cp + $delta) & 0xFFFF;
                } else {
                    $off = $ro + 2 * ($cp - $start) + ($idRange + $i * 2);
                    $gid = PdfByteReader::be16($sub, $off);
                    if ($gid !== 0) $gid = ($gid + $delta) & 0xFFFF;
                }
                if ($gid !== 0) $map[$cp] = $gid;
            }
        }
        return $map;
    }

    private function parseCmapF12(string $sub): array
    {
        $nGroups = PdfByteReader::beU32($sub, 12);
        $map = [];
        $pos = 16;
        for ($i = 0; $i < $nGroups; $i++) {
            $startChar = PdfByteReader::beU32($sub, $pos);
            $endChar = PdfByteReader::beU32($sub, $pos + 4);
            $startGid = PdfByteReader::beU32($sub, $pos + 8);
            $pos += 12;

            for ($cp = $startChar; $cp <= $endChar; $cp++) {
                $map[$cp] = $startGid + ($cp - $startChar);
            }
        }
        return $map;
    }
}