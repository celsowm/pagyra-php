<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Font;

use Celsowm\PagyraPhp\Core\PdfBuilder;
use Celsowm\PagyraPhp\Core\PdfStreamBuilder;
use Celsowm\PagyraPhp\Font\PdfTTFParser;

final class PdfFontManager
{
    private PdfBuilder $pdf;
    private array $fonts = [];
    private array $usedGids = [];
    private array $fontVariants = [];

    public function __construct(PdfBuilder $pdf)
    {
        $this->pdf = $pdf;
    }

    public function addTTFFont(string $alias, string $ttfPath): void
    {
        if (!is_file($ttfPath) || !is_readable($ttfPath)) {
            throw new \RuntimeException("Arquivo TTF não encontrado ou sem permissão: {$ttfPath}");
        }
        $raw = file_get_contents($ttfPath);
        if ($raw === false || strlen($raw) < 100) {
            throw new \RuntimeException("Arquivo TTF parece inválido: {$ttfPath}");
        }
        $parser = new PdfTTFParser($raw);
        $head = $parser->parseHead();
        $hhea = $parser->parseHhea();
        $maxp = $parser->parseMaxp();
        $hmtx = $parser->parseHmtx($hhea['numberOfHMetrics'], $maxp['numGlyphs']);
        $name = $parser->parseNamePostScript();
        $name = $name ? $this->sanitizePSName($name) : $this->sanitizePSName('EmbeddedTTF-' . $alias);
        $cmap = $parser->parseCmap();
        $italicAngle = 0.0;
        try {
            $post = $parser->parsePost();
            $italicAngle = (float)$post['italicAngle'];
        } catch (\Throwable $e) {
        }
        $this->fonts[$alias] = [
            'ttf' => $raw,
            'name' => $name,
            'unitsPerEm' => max(16, (int)$head['unitsPerEm']),
            'ascent' => $hhea['ascent'],
            'descent' => $hhea['descent'],
            'bbox' => $head['bbox'],
            'adv' => $hmtx['adv'],
            'gidCount' => $maxp['numGlyphs'],
            'cmap' => $cmap,
            'italicAngle' => $italicAngle,
        ];
        $this->usedGids[$alias] = [0 => 0];
    }

    public function bindFontVariants(string $baseAlias, array $map): void
    {
        $this->fontVariants[$baseAlias] = array_merge(
            ['R' => $baseAlias, 'B' => null, 'I' => null, 'BI' => null],
            array_change_key_case($map, CASE_UPPER)
        );
    }

    public function fontExists(string $alias): bool
    {
        return isset($this->fonts[$alias]);
    }

    public function getFonts(): array
    {
        return $this->fonts;
    }

    public function updateUsedGid(string $alias, int $gid, int $cp): void
    {
        if (!isset($this->usedGids[$alias][$gid])) {
            $this->usedGids[$alias][$gid] = $cp;
        }
    }

    public function resolveAliasByStyle(string $baseAlias, string $style): string
    {
        $style = strtoupper($style);
        $key = (str_contains($style, 'B') ? 'B' : '') . (str_contains($style, 'I') ? 'I' : '');
        if ($key === '') {
            $key = 'R';
        }
        $v = $this->fontVariants[$baseAlias][$key] ?? null;
        return $v ?: $baseAlias;
    }

    public function emitFontObjects(): array
    {
        $type0Ids = [];
        foreach ($this->fonts as $alias => $font) {
            if (empty($this->usedGids[$alias])) continue;

            $spaceGid = $font['cmap'][0x20] ?? null;
            $dwUnits = $spaceGid !== null ? (int)$font['adv'][$spaceGid] : (int)round(array_sum($font['adv']) / max(1, count($font['adv'])));
            $DW = (int)round(($dwUnits * 1000) / $font['unitsPerEm']);
            $W = [];
            foreach ($this->usedGids[$alias] as $gid => $_) {
                $w = (int)round((($font['adv'][$gid] ?? $dwUnits) * 1000) / $font['unitsPerEm']);
                if ($w !== $DW) $W[] = "{$gid} [{$w}]";
            }
            $WArr = count($W) ? "/W [ " . implode(' ', $W) . " ]" : "";
            $toUniId = $this->pdf->newObjectId();
            $this->pdf->setObject($toUniId, PdfStreamBuilder::streamObj($this->buildToUnicodeCMap($font['name'], $this->usedGids[$alias])));
            $fileId = $this->pdf->newObjectId();
            $this->pdf->setObject($fileId, PdfStreamBuilder::streamObj($font['ttf']));
            $descId = $this->pdf->newObjectId();
            $bboxPdf = array_map(fn($v) => (int)round(($v * 1000) / $font['unitsPerEm']), $font['bbox']);
            $ascent = (int)round(($font['ascent'] * 1000) / $font['unitsPerEm']);
            $descent = (int)round(($font['descent'] * 1000) / $font['unitsPerEm']);
            $italicAnglePdf = (int)round(($font['italicAngle'] ?? 0.0));
            $this->pdf->setObject($descId, "<< /Type /FontDescriptor /FontName /{$font['name']} /Flags 32 " .
                "/Ascent {$ascent} /Descent {$descent} /CapHeight {$ascent} /ItalicAngle {$italicAnglePdf} " .
                "/FontBBox [ {$bboxPdf[0]} {$bboxPdf[1]} {$bboxPdf[2]} {$bboxPdf[3]} ] " .
                "/StemV 80 /FontFile2 {$fileId} 0 R >>");
            $cidId = $this->pdf->newObjectId();
            $cidInfo = "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>";
            $this->pdf->setObject($cidId, "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /{$font['name']} {$cidInfo} /CIDToGIDMap /Identity /DW {$DW} {$WArr} /FontDescriptor {$descId} 0 R >>");
            $type0Id = $this->pdf->newObjectId();
            $this->pdf->setObject($type0Id, "<< /Type /Font /Subtype /Type0 /BaseFont /{$font['name']} /Encoding /Identity-H /DescendantFonts [ {$cidId} 0 R ] /ToUnicode {$toUniId} 0 R >>");
            $type0Ids[$alias] = $type0Id;
        }
        return $type0Ids;
    }

    private function buildToUnicodeCMap(string $psName, array $gidToUni): string
    {
        $pairs = [];
        foreach ($gidToUni as $gid => $uni) $pairs[] = sprintf("<%04X> <%04X>\n", $gid, $uni);
        $chunks = array_chunk($pairs, 100);
        $bf = '';
        foreach ($chunks as $chunk) $bf .= count($chunk) . " beginbfchar\n" . implode('', $chunk) . "endbfchar\n";
        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n" .
            "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> def\n" .
            "/CMapName /{$psName} def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n" .
            $bf . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    private function sanitizePSName(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9\-\+_]/', '', str_replace(' ', '-', $s)) ?: 'Embedded';
    }
}
