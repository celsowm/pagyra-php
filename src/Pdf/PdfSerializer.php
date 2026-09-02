<?php

declare(strict_types=1);

namespace Pagyra\Pdf;

use Pagyra\Css\Color\Rgba;
use Pagyra\Fonts\FontRegistry;
use Pagyra\Fonts\RegisteredFont;
use Pagyra\Fonts\WinAnsiEncoding;
use Pagyra\Fonts\Ttf\TtfSubsetter;
use Pagyra\Paint\BorderPaintCommand;
use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\DisplayList;
use Pagyra\Paint\ImagePaintCommand;
use Pagyra\Paint\RoundedBorderPaintCommand;
use Pagyra\Paint\TextPaintCommand;
use Pagyra\Units\Units;

final class PdfSerializer
{
    private const KAPPA = 0.5522847498307936;

    public function serialize(DisplayList $displayList, ?FontRegistry $fontRegistry = null, float $contentScale = 1.0): string
    {
        $contentScale = $contentScale > 0 ? $contentScale : 1.0;
        $objects = [];
        $nextId = 1;
        $reserve = static function () use (&$objects, &$nextId): int {
            $id = $nextId++;
            $objects[$id] = '';
            return $id;
        };

        $catalogId = $reserve();
        $pagesId = $reserve();
        $usage = $this->collectFontUsage($displayList, $fontRegistry);
        $fontResources = $this->buildFontResources($usage, $objects, $reserve);
        $imageResources = $this->buildImageResources($displayList, $objects, $reserve);
        $extGStateResources = $this->buildExtGStateResources($displayList, $objects, $reserve);

        $pageIds = [];
        foreach ($displayList->pages as $page) {
            $content = '';
            $usedFonts = [];
            $usedImages = [];
            $linkAnnotations = [];
            foreach ($page->commands as $command) {
                if ($command instanceof BoxPaintCommand) {
                    $content .= $this->serializeBox(
                        $command,
                        $page->height,
                        $this->graphicsStateName($command->backgroundColor, $extGStateResources),
                    );
                    continue;
                }
                if ($command instanceof RoundedBorderPaintCommand) {
                    $content .= $this->serializeRoundedBorder(
                        $command,
                        $page->height,
                        $this->graphicsStateName($command->color, $extGStateResources),
                    );
                    continue;
                }
                if ($command instanceof BorderPaintCommand) {
                    $content .= $this->serializeBorder(
                        $command,
                        $page->height,
                        $this->graphicsStateName($command->color, $extGStateResources),
                    );
                    continue;
                }
                if ($command instanceof ImagePaintCommand) {
                    $key = hash('sha256', $command->bytes);
                    $resource = $imageResources[$key] ?? null;
                    if ($resource !== null) {
                        $usedImages[$resource['name']] = $resource['id'];
                        $content .= $this->serializeImage($command, $page->height, $resource['name']);
                    }
                    continue;
                }
                if (!$command instanceof TextPaintCommand) continue;

                [$key] = $this->fontChoice($command, $fontRegistry);
                $resource = $fontResources[$key];
                $usedFonts[$resource['name']] = $resource['id'];
                $graphicsState = $this->graphicsStateName($command->color, $extGStateResources);
                $content .= $resource['face'] instanceof RegisteredFont
                    ? $this->serializeEmbeddedText($command, $page->height, $resource['name'], $resource['face'], $graphicsState)
                    : $this->serializeBase14Text($command, $page->height, $resource['name'], $graphicsState);
                $content .= $this->serializeTextDecorations($command, $page->height, $graphicsState);

                if ($command->linkHref !== null && $command->linkHref !== '') {
                    $linkAnnotations[] = $command;
                }
            }

            $annotIds = [];
            foreach ($linkAnnotations as $linkCommand) {
                $annotIds[] = $this->buildLinkAnnotation($linkCommand, $page->height, $contentScale, $objects, $reserve);
            }
            $annots = $annotIds === [] ? '' : ' /Annots [' . implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $annotIds)) . ']';

            $content = $this->applyContentScale($content, $contentScale);

            $contentId = $reserve();
            $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream";
            $pageId = $reserve();
            $pageIds[] = $pageId;

            $fonts = '';
            foreach ($usedFonts as $resourceName => $fontId) $fonts .= '/' . $resourceName . ' ' . $fontId . ' 0 R ';
            $images = '';
            foreach ($usedImages as $resourceName => $imageId) $images .= '/' . $resourceName . ' ' . $imageId . ' 0 R ';
            $states = '';
            foreach ($extGStateResources as $state) $states .= '/' . $state['name'] . ' ' . $state['id'] . ' 0 R ';
            $resources = '<< /Font << ' . $fonts . '>> /XObject << ' . $images . '>> /ExtGState << ' . $states . '>> >>';
            $widthPt = $this->number(Units::pxToPt($page->width) * $contentScale);
            $heightPt = $this->number(Units::pxToPt($page->height) * $contentScale);
            $objects[$pageId] = '<< /Type /Page /Parent ' . $pagesId . ' 0 R '
                . '/MediaBox [0 0 ' . $widthPt . ' ' . $heightPt . '] '
                . '/Resources ' . $resources . ' /Contents ' . $contentId . ' 0 R' . $annots . ' >>';
        }

        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds));
        $objects[$pagesId] = '<< /Type /Pages /Count ' . count($pageIds) . ' /Kids [' . $kids . '] >>';
        $objects[$catalogId] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';
        return $this->assemble($objects, $catalogId);
    }

    private function buildFontResources(array $usage, array &$objects, callable $reserve): array
    {
        $resources = [];
        $fontIndex = 1;
        foreach ($usage as $key => $entry) {
            $resourceName = 'F' . $fontIndex++;
            $face = $entry['face'];
            if (!$face instanceof RegisteredFont) {
                $fontId = $reserve();
                $baseFont = (string) $entry['base14'];
                $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $baseFont . ' /Encoding /WinAnsiEncoding >>';
                $resources[$key] = ['name' => $resourceName, 'id' => $fontId, 'face' => null];
                continue;
            }

            $fontFileId = $reserve();
            $descriptorId = $reserve();
            $cidFontId = $reserve();
            $toUnicodeId = $reserve();
            $type0Id = $reserve();
            $baseFont = $this->embeddedBaseFontName($face);
            $metrics = $face->metrics;
            $glyphIds = array_map('intval', array_keys($entry['glyphs']));
            $fontProgram = (new TtfSubsetter())->subset($face->binary, $glyphIds) ?? $face->binary;
            $objects[$fontFileId] = '<< /Length ' . strlen($fontProgram) . ' /Length1 ' . strlen($fontProgram)
                . ">>\nstream\n" . $fontProgram . "\nendstream";

            $scale = 1000.0 / $metrics->unitsPerEm;
            $bbox = $metrics->bbox;
            $fontBBox = '[' . $this->number($bbox['xMin'] * $scale) . ' ' . $this->number($bbox['yMin'] * $scale) . ' '
                . $this->number($bbox['xMax'] * $scale) . ' ' . $this->number($bbox['yMax'] * $scale) . ']';
            $ascent = $this->number($metrics->ascent * $scale);
            $descent = $this->number($metrics->descent * $scale);
            $flags = $face->style === 'italic' ? 96 : 32;
            $objects[$descriptorId] = '<< /Type /FontDescriptor /FontName /' . $baseFont . ' /Flags ' . $flags
                . ' /FontBBox ' . $fontBBox . ' /ItalicAngle ' . ($face->style === 'italic' ? '-12' : '0')
                . ' /Ascent ' . $ascent . ' /Descent ' . $descent . ' /CapHeight ' . $ascent
                . ' /StemV 80 /FontFile2 ' . $fontFileId . ' 0 R >>';

            $widths = $this->cidWidths($face, $glyphIds);
            $defaultWidth = $this->number($metrics->advanceWidth(0) * $scale);
            $objects[$cidFontId] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $baseFont
                . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
                . ' /FontDescriptor ' . $descriptorId . ' 0 R /DW ' . $defaultWidth
                . ($widths === '' ? '' : ' /W [' . $widths . ']') . ' /CIDToGIDMap /Identity >>';

            $cmap = $this->toUnicodeCMap($baseFont, $entry['glyphs']);
            $objects[$toUnicodeId] = '<< /Length ' . strlen($cmap) . ">>\nstream\n" . $cmap . "endstream";
            $objects[$type0Id] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $baseFont
                . ' /Encoding /Identity-H /DescendantFonts [' . $cidFontId . ' 0 R] /ToUnicode ' . $toUnicodeId . ' 0 R >>';
            $resources[$key] = ['name' => $resourceName, 'id' => $type0Id, 'face' => $face];
        }
        return $resources;
    }

    private function buildImageResources(DisplayList $displayList, array &$objects, callable $reserve): array
    {
        $resources = [];
        $index = 1;
        $pngParser = new PngPdfImageParser();

        foreach ($displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if (!$command instanceof ImagePaintCommand) continue;
                $key = hash('sha256', $command->bytes);
                if (isset($resources[$key])) continue;

                $dictionary = null;
                $stream = null;
                if ($command->metadata->format === 'jpeg') {
                    $colorSpace = match ($command->metadata->channels) {
                        1 => '/DeviceGray',
                        4 => '/DeviceCMYK',
                        default => '/DeviceRGB',
                    };
                    $stream = $command->bytes;
                    $dictionary = '<< /Type /XObject /Subtype /Image'
                        . ' /Width ' . $command->metadata->width
                        . ' /Height ' . $command->metadata->height
                        . ' /ColorSpace ' . $colorSpace
                        . ' /BitsPerComponent ' . $command->metadata->bitsPerChannel
                        . ' /Filter /DCTDecode /Length ' . strlen($stream) . ' >>';
                } elseif ($command->metadata->format === 'png') {
                    $png = $pngParser->parse($command->bytes);
                    if ($png !== null) {
                        $stream = $png->compressedData;
                        $softMask = '';
                        if ($png->alphaCompressedData !== null) {
                            $softMaskId = $reserve();
                            $objects[$softMaskId] = '<< /Type /XObject /Subtype /Image'
                                . ' /Width ' . $png->width
                                . ' /Height ' . $png->height
                                . ' /ColorSpace /DeviceGray /BitsPerComponent 8'
                                . ' /Filter /FlateDecode /Length ' . strlen($png->alphaCompressedData) . ">>\nstream\n"
                                . $png->alphaCompressedData . "\nendstream";
                            $softMask = ' /SMask ' . $softMaskId . ' 0 R';
                        }

                        $decodeParms = $png->usesPngPredictor
                            ? ' /DecodeParms << /Predictor 15 /Colors ' . $png->colors
                                . ' /BitsPerComponent ' . $png->bitsPerComponent
                                . ' /Columns ' . $png->width . ' >>'
                            : '';
                        $colorKeyMask = $png->colorKeyMask !== null ? ' /Mask [' . $png->colorKeyMask . ']' : '';
                        $dictionary = '<< /Type /XObject /Subtype /Image'
                            . ' /Width ' . $png->width
                            . ' /Height ' . $png->height
                            . ' /ColorSpace ' . $png->colorSpace
                            . ' /BitsPerComponent ' . $png->bitsPerComponent
                            . ' /Filter /FlateDecode' . $decodeParms . $softMask . $colorKeyMask
                            . ' /Length ' . strlen($stream) . ' >>';
                    }
                }

                if ($dictionary === null || $stream === null) continue;
                $id = $reserve();
                $name = 'Im' . $index++;
                $objects[$id] = $dictionary . "\nstream\n" . $stream . "\nendstream";
                $resources[$key] = ['name' => $name, 'id' => $id];
            }
        }
        return $resources;
    }

    private function buildExtGStateResources(DisplayList $displayList, array &$objects, callable $reserve): array
    {
        $resources = [];
        $index = 1;
        foreach ($displayList->pages as $page) {
            foreach ($page->commands as $command) {
                $color = match (true) {
                    $command instanceof BoxPaintCommand => $command->backgroundColor,
                    $command instanceof RoundedBorderPaintCommand => $command->color,
                    $command instanceof BorderPaintCommand => $command->color,
                    $command instanceof TextPaintCommand => $command->color,
                    default => null,
                };
                if (!$color instanceof Rgba || $color->a <= 0.0 || $color->a >= 1.0) continue;
                $key = $this->alphaKey($color->a);
                if (isset($resources[$key])) continue;
                $id = $reserve();
                $name = 'GS' . $index++;
                $alpha = $this->number($color->a);
                $objects[$id] = '<< /Type /ExtGState /ca ' . $alpha . ' /CA ' . $alpha . ' >>';
                $resources[$key] = ['name' => $name, 'id' => $id];
            }
        }
        return $resources;
    }

    /**
     * Builds a `/Subtype /Link` annotation over a text run's bounding box, pointing to its
     * `<a href>` (not implemented in pagyra-js, which has the general Annots plumbing in its
     * PDF builder but nothing that ever populates it for a link; this is a fresh PDF-standard
     * implementation rather than a port). Text already renders correctly without this; the gap
     * this closes is that the rendered text was not clickable.
     */
    /**
     * Scales a page's whole drawing about the PDF origin. Pagyra lays the document
     * out on a page inflated by 1/contentScale (see RenderHtmlOptions::scaledForContentZoom),
     * so this scale-down brings the page back to its requested physical size while ~1/contentScale
     * more content sits on each sheet. contentScale 1.0 leaves the stream byte-identical;
     * 0.8 reproduces the wkhtmltopdf "everything at 0.8x" zoom for side-by-side comparison.
     */
    private function applyContentScale(string $content, float $contentScale): string
    {
        if (abs($contentScale - 1.0) < 1e-9 || $content === '') {
            return $content;
        }
        $scale = $this->number($contentScale);
        return "q\n" . $scale . ' 0 0 ' . $scale . " 0 0 cm\n" . $content . "Q\n";
    }

    private function buildLinkAnnotation(TextPaintCommand $command, float $pageHeightPx, float $contentScale, array &$objects, callable $reserve): int
    {
        $widthPx = max($command->run->width, 0.0);
        $heightPx = max($command->run->height, $command->fontSize);
        $x1 = Units::pxToPt($command->x) * $contentScale;
        $x2 = Units::pxToPt($command->x + $widthPx) * $contentScale;
        $y1 = Units::pxToPt($pageHeightPx - ($command->y + $heightPx)) * $contentScale;
        $y2 = Units::pxToPt($pageHeightPx - $command->y) * $contentScale;
        $id = $reserve();
        $objects[$id] = '<< /Type /Annot /Subtype /Link /Rect [' . $this->number($x1) . ' ' . $this->number($y1) . ' '
            . $this->number($x2) . ' ' . $this->number($y2) . '] /Border [0 0 0] '
            . '/A << /Type /Action /S /URI /URI (' . $this->escapePdfString((string) $command->linkHref) . ') >> >>';
        return $id;
    }

    private function graphicsStateName(?Rgba $color, array $resources): ?string
    {
        if (!$color instanceof Rgba || $color->a <= 0.0 || $color->a >= 1.0) return null;
        return $resources[$this->alphaKey($color->a)]['name'] ?? null;
    }

    private function alphaKey(float $alpha): string
    {
        return number_format(max(0.0, min(1.0, $alpha)), 6, '.', '');
    }

    private function collectFontUsage(DisplayList $displayList, ?FontRegistry $fontRegistry): array
    {
        $usage = [];
        foreach ($displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if (!$command instanceof TextPaintCommand) continue;
                [$key, $face, $base14] = $this->fontChoice($command, $fontRegistry);
                if (!isset($usage[$key])) $usage[$key] = ['face' => $face, 'base14' => $base14, 'glyphs' => []];
                if ($face === null) continue;
                foreach ($this->codePoints($command->text) as $codePoint) {
                    $gid = $face->metrics->glyphId($codePoint);
                    if (!isset($usage[$key]['glyphs'][$gid])) $usage[$key]['glyphs'][$gid] = $codePoint;
                }
            }
        }
        return $usage;
    }

    private function fontChoice(TextPaintCommand $command, ?FontRegistry $registry): array
    {
        $face = $registry?->resolveFace($command->fontFamily, $command->fontWeight, $command->fontStyle);
        if ($face !== null && $face->binary !== '' && $this->isTrueType($face->binary)) return ['embedded:' . spl_object_id($face), $face, null];
        $base14 = $this->base14Font($command);
        return ['base14:' . $base14, null, $base14];
    }

    private function isTrueType(string $binary): bool
    {
        return strlen($binary) >= 4 && (substr($binary, 0, 4) === "\x00\x01\x00\x00" || substr($binary, 0, 4) === 'true');
    }

    private function embeddedBaseFontName(RegisteredFont $face): string
    {
        $family = preg_replace('/[^A-Za-z0-9_.-]+/', '', $face->family) ?? '';
        if ($family === '') $family = 'PagyraFont';
        return $family . '-' . strtoupper(substr(hash('sha256', $face->binary . ':' . $face->weight . ':' . $face->style), 0, 8));
    }

    private function cidWidths(RegisteredFont $face, array $glyphIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $glyphIds)));
        sort($ids, SORT_NUMERIC);
        $scale = 1000.0 / $face->metrics->unitsPerEm;
        $parts = [];
        foreach ($ids as $gid) $parts[] = $gid . ' [' . $this->number($face->metrics->advanceWidth($gid) * $scale) . ']';
        return implode(' ', $parts);
    }

    private function toUnicodeCMap(string $name, array $glyphToCodePoint): string
    {
        ksort($glyphToCodePoint, SORT_NUMERIC);
        $entries = [];
        foreach ($glyphToCodePoint as $gid => $codePoint) $entries[] = '<' . sprintf('%04X', $gid & 0xFFFF) . '> <' . $this->utf16BeHex($codePoint) . '>';
        $body = '';
        foreach (array_chunk($entries, 100) as $chunk) $body .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        $cmapName = preg_replace('/[^A-Za-z0-9_.-]+/', '', $name) ?: 'PagyraUnicode';
        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . '/CMapName /' . $cmapName . " def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $body . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    private function utf16BeHex(int $codePoint): string
    {
        if ($codePoint <= 0xFFFF) return sprintf('%04X', $codePoint);
        $value = $codePoint - 0x10000;
        return sprintf('%04X%04X', 0xD800 + (($value >> 10) & 0x3FF), 0xDC00 + ($value & 0x3FF));
    }

    private function serializeBox(BoxPaintCommand $command, float $pageHeightPx, ?string $graphicsState = null): string
    {
        $color = $command->backgroundColor;
        if ($color === null || $color->a <= 0.0 || $command->width <= 0.0 || $command->height <= 0.0) return '';
        if ($command->borderRadius->isZero()) {
            return $this->serializeFilledRect(
                $command->x,
                $command->y,
                $command->width,
                $command->height,
                $pageHeightPx,
                $color,
                $graphicsState,
            );
        }

        [$r, $g, $b] = $color->toPdfRgb();
        $geometry = RoundedRectPdfPath::build(
            $command->x,
            $command->y,
            $command->width,
            $command->height,
            $pageHeightPx,
            $command->borderRadius,
        );
        return "q\n"
            . ($graphicsState !== null ? '/' . $graphicsState . " gs\n" : '')
            . $this->number($r) . ' ' . $this->number($g) . ' ' . $this->number($b) . " rg\n"
            . $this->roundedRectPath($geometry) . "f\nQ\n";
    }

    private function roundedRectPath(array $g): string
    {
        $x = $g['x'];
        $bottom = $g['bottom'];
        $right = $g['right'];
        $top = $g['top'];
        $tlx = $g['tlx']; $tly = $g['tly'];
        $trx = $g['trx']; $try = $g['try'];
        $brx = $g['brx']; $bry = $g['bry'];
        $blx = $g['blx']; $bly = $g['bly'];
        $k = self::KAPPA;

        $out = $this->number($x + $tlx) . ' ' . $this->number($top) . " m\n";
        $out .= $this->number($right - $trx) . ' ' . $this->number($top) . " l\n";
        if ($trx > 0.0 || $try > 0.0) {
            $out .= $this->number($right - $trx + $k * $trx) . ' ' . $this->number($top) . ' '
                . $this->number($right) . ' ' . $this->number($top - $try + $k * $try) . ' '
                . $this->number($right) . ' ' . $this->number($top - $try) . " c\n";
        }
        $out .= $this->number($right) . ' ' . $this->number($bottom + $bry) . " l\n";
        if ($brx > 0.0 || $bry > 0.0) {
            $out .= $this->number($right) . ' ' . $this->number($bottom + $bry - $k * $bry) . ' '
                . $this->number($right - $brx + $k * $brx) . ' ' . $this->number($bottom) . ' '
                . $this->number($right - $brx) . ' ' . $this->number($bottom) . " c\n";
        }
        $out .= $this->number($x + $blx) . ' ' . $this->number($bottom) . " l\n";
        if ($blx > 0.0 || $bly > 0.0) {
            $out .= $this->number($x + $blx - $k * $blx) . ' ' . $this->number($bottom) . ' '
                . $this->number($x) . ' ' . $this->number($bottom + $bly - $k * $bly) . ' '
                . $this->number($x) . ' ' . $this->number($bottom + $bly) . " c\n";
        }
        $out .= $this->number($x) . ' ' . $this->number($top - $tly) . " l\n";
        if ($tlx > 0.0 || $tly > 0.0) {
            $out .= $this->number($x) . ' ' . $this->number($top - $tly + $k * $tly) . ' '
                . $this->number($x + $tlx - $k * $tlx) . ' ' . $this->number($top) . ' '
                . $this->number($x + $tlx) . ' ' . $this->number($top) . " c\n";
        }
        return $out . "h\n";
    }

    private function serializeRoundedBorder(RoundedBorderPaintCommand $command, float $pageHeightPx, ?string $graphicsState = null): string
    {
        if ($command->color->a <= 0.0 || $command->width <= 0.0 || $command->height <= 0.0 || $command->borderWidth <= 0.0) return '';
        [$r, $g, $b] = $command->color->toPdfRgb();
        $outer = RoundedRectPdfPath::build(
            $command->x,
            $command->y,
            $command->width,
            $command->height,
            $pageHeightPx,
            $command->outerRadius,
        );
        $innerWidth = max(0.0, $command->width - 2.0 * $command->borderWidth);
        $innerHeight = max(0.0, $command->height - 2.0 * $command->borderWidth);
        $paths = $this->roundedRectPath($outer);
        if ($innerWidth > 0.0 && $innerHeight > 0.0) {
            $inner = RoundedRectPdfPath::build(
                $command->x + $command->borderWidth,
                $command->y + $command->borderWidth,
                $innerWidth,
                $innerHeight,
                $pageHeightPx,
                $command->innerRadius,
            );
            $paths .= $this->roundedRectPath($inner);
        }
        return "q\n"
            . ($graphicsState !== null ? '/' . $graphicsState . " gs\n" : '')
            . $this->number($r) . ' ' . $this->number($g) . ' ' . $this->number($b) . " rg\n"
            . $paths . "f*\nQ\n";
    }

    private function serializeBorder(BorderPaintCommand $command, float $pageHeightPx, ?string $graphicsState = null): string
    {
        if ($command->color->a <= 0.0 || $command->width <= 0.0 || $command->height <= 0.0) return '';
        return $this->serializeFilledRect(
            $command->x,
            $command->y,
            $command->width,
            $command->height,
            $pageHeightPx,
            $command->color,
            $graphicsState,
        );
    }

    /**
     * Paints `text-decoration: underline` / `line-through` as thin filled rectangles.
     *
     * Position/thickness ratios (relative to font size) mirror pagyra-js's
     * TextDecorationRenderer::renderSolid so both implementations produce the same
     * geometry for the currently supported `solid` style. `overline` and the
     * `double`/`dashed`/`dotted`/`wavy` styles are deliberately not ported yet.
     */
    private function serializeTextDecorations(TextPaintCommand $command, float $pageHeightPx, ?string $graphicsState = null): string
    {
        if (!$command->underline && !$command->lineThrough) return '';
        if ($command->color instanceof Rgba && $command->color->a <= 0.0) return '';
        $widthPx = max($command->run->width, 0.0);
        if ($widthPx <= 0.0) return '';
        $color = $command->color ?? new Rgba(0, 0, 0, 1.0);

        $content = '';
        if ($command->lineThrough) {
            $thicknessPx = max($command->fontSize * 0.085, 0.5);
            $centerYPx = $command->baseline - $command->fontSize * 0.3;
            $content .= $this->serializeFilledRect($command->x, $centerYPx - $thicknessPx / 2, $widthPx, $thicknessPx, $pageHeightPx, $color, $graphicsState);
        }
        if ($command->underline) {
            $thicknessPx = max($command->fontSize * 0.065, 0.5);
            $underlineYPx = $command->baseline + $command->fontSize * 0.1;
            $content .= $this->serializeFilledRect($command->x, $underlineYPx - $thicknessPx / 2, $widthPx, $thicknessPx, $pageHeightPx, $color, $graphicsState);
        }
        return $content;
    }

    private function serializeFilledRect(
        float $xPx,
        float $yPx,
        float $widthPx,
        float $heightPx,
        float $pageHeightPx,
        Rgba $color,
        ?string $graphicsState,
    ): string {
        $x = Units::pxToPt($xPx);
        $y = Units::pxToPt($pageHeightPx - $yPx - $heightPx);
        [$r, $g, $b] = $color->toPdfRgb();
        return "q\n"
            . ($graphicsState !== null ? '/' . $graphicsState . " gs\n" : '')
            . $this->number($r) . ' ' . $this->number($g) . ' ' . $this->number($b) . " rg\n"
            . $this->number($x) . ' ' . $this->number($y) . ' ' . $this->number(Units::pxToPt($widthPx)) . ' '
            . $this->number(Units::pxToPt($heightPx)) . " re f\nQ\n";
    }

    private function serializeImage(ImagePaintCommand $command, float $pageHeightPx, string $resourceName): string
    {
        if ($command->width <= 0.0 || $command->height <= 0.0) return '';
        $width = Units::pxToPt($command->width);
        $height = Units::pxToPt($command->height);
        $x = Units::pxToPt($command->x);
        $y = Units::pxToPt($pageHeightPx - $command->y - $command->height);
        $content = "q\n";
        if ($command->clipRect !== null) {
            if ($command->clipRadius !== null && !$command->clipRadius->isZero()) {
                $clipGeometry = RoundedRectPdfPath::build(
                    $command->clipRect->x,
                    $command->clipRect->y,
                    $command->clipRect->width,
                    $command->clipRect->height,
                    $pageHeightPx,
                    $command->clipRadius,
                );
                $content .= $this->roundedRectPath($clipGeometry) . "W n\n";
            } else {
                $clipX = Units::pxToPt($command->clipRect->x);
                $clipY = Units::pxToPt($pageHeightPx - $command->clipRect->y - $command->clipRect->height);
                $clipWidth = Units::pxToPt($command->clipRect->width);
                $clipHeight = Units::pxToPt($command->clipRect->height);
                $content .= $this->number($clipX) . ' ' . $this->number($clipY) . ' '
                    . $this->number($clipWidth) . ' ' . $this->number($clipHeight) . " re W n\n";
            }
        }
        $content .= $this->number($width) . ' 0 0 ' . $this->number($height) . ' '
            . $this->number($x) . ' ' . $this->number($y) . " cm\n/" . $resourceName . " Do\nQ\n";
        return $content;
    }

    private function serializeEmbeddedText(
        TextPaintCommand $command,
        float $pageHeightPx,
        string $resourceName,
        RegisteredFont $face,
        ?string $graphicsState = null,
    ): string {
        if ($command->color instanceof Rgba && $command->color->a <= 0.0) return '';
        $codePoints = $this->codePoints($command->text);
        $glyphs = array_map(fn (int $cp): int => $face->metrics->glyphId($cp), $codePoints);
        $wordSpacing = $this->spacingPx($command, 'word-spacing');
        $items = [];
        $last = count($glyphs) - 1;
        foreach ($glyphs as $i => $gid) {
            $items[] = '<' . sprintf('%04X', $gid & 0xFFFF) . '>';
            if ($i >= $last) continue;
            $adjustment = 0.0;
            $kern = $face->metrics->kerning($gid, $glyphs[$i + 1]);
            if ($kern !== 0) $adjustment += -$kern * 1000.0 / $face->metrics->unitsPerEm;
            if (($codePoints[$i] ?? null) === 0x20 && $wordSpacing !== 0.0 && $command->fontSize > 0.0) $adjustment += -$wordSpacing * 1000.0 / $command->fontSize;
            if (abs($adjustment) > 0.0000001) $items[] = $this->number($adjustment);
        }
        $x = Units::pxToPt($command->x);
        $y = Units::pxToPt($pageHeightPx - $command->baseline);
        $fontSize = Units::pxToPt($command->fontSize);
        $letterSpacingPt = Units::pxToPt($this->spacingPx($command, 'letter-spacing'));
        [$r, $g, $b] = $command->color?->toPdfRgb() ?? [0.0, 0.0, 0.0];
        $text = "BT\n/" . $resourceName . ' ' . $this->number($fontSize) . " Tf\n"
            . ($letterSpacingPt !== 0.0 ? $this->number($letterSpacingPt) . " Tc\n" : '')
            . $this->number($r) . ' ' . $this->number($g) . ' ' . $this->number($b) . " rg\n1 0 0 1 "
            . $this->number($x) . ' ' . $this->number($y) . " Tm\n[" . implode(' ', $items) . "] TJ\nET\n";
        return $graphicsState !== null ? "q\n/" . $graphicsState . " gs\n" . $text . "Q\n" : $text;
    }

    private function serializeBase14Text(
        TextPaintCommand $command,
        float $pageHeightPx,
        string $resourceName,
        ?string $graphicsState = null,
    ): string {
        if ($command->color instanceof Rgba && $command->color->a <= 0.0) return '';
        $encoded = $this->encodeWinAnsi($command->text);
        $x = Units::pxToPt($command->x);
        $y = Units::pxToPt($pageHeightPx - $command->baseline);
        $fontSize = Units::pxToPt($command->fontSize);
        $letterSpacingPt = Units::pxToPt($this->spacingPx($command, 'letter-spacing'));
        $wordSpacingPt = Units::pxToPt($this->spacingPx($command, 'word-spacing'));
        [$r, $g, $b] = $command->color?->toPdfRgb() ?? [0.0, 0.0, 0.0];
        $text = "BT\n/" . $resourceName . ' ' . $this->number($fontSize) . " Tf\n"
            . ($letterSpacingPt !== 0.0 ? $this->number($letterSpacingPt) . " Tc\n" : '')
            . ($wordSpacingPt !== 0.0 ? $this->number($wordSpacingPt) . " Tw\n" : '')
            . $this->number($r) . ' ' . $this->number($g) . ' ' . $this->number($b) . " rg\n1 0 0 1 "
            . $this->number($x) . ' ' . $this->number($y) . " Tm\n(" . $this->escapePdfString($encoded) . ") Tj\nET\n";
        return $graphicsState !== null ? "q\n/" . $graphicsState . " gs\n" . $text . "Q\n" : $text;
    }

    private function spacingPx(TextPaintCommand $command, string $property): float
    {
        $value = $command->run->style->get($property);
        return $value !== null && preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $m) === 1 ? (float) $m[1] : 0.0;
    }

    private function base14Font(TextPaintCommand $command): string
    {
        // Walk the whole `font-family` stack, not just the first name: `Calibri, sans-serif`
        // has no Calibri here, so the generic `sans-serif` at the end is what actually decides
        // the fallback. Picking a bucket from the first name alone sent every `Calibri, …`
        // (i.e. every eproc/TJRJ document) to Times while the width table — which does fall
        // through — measured it as Helvetica, so justified lines never reached the margin.
        $base = null;
        foreach (explode(',', strtolower($command->fontFamily ?? '')) as $name) {
            $name = trim($name, " \t\n\r\0\x0B\"'");
            if ($name === '') continue;
            if (str_contains($name, 'courier') || str_contains($name, 'mono')) { $base = 'Courier'; break; }
            if (str_contains($name, 'helvetica') || str_contains($name, 'arial') || str_contains($name, 'sans')) { $base = 'Helvetica'; break; }
            if (str_contains($name, 'times') || str_contains($name, 'georgia') || str_contains($name, 'serif')) { $base = 'Times'; break; }
            // Unknown family name — keep looking at the next fallback in the stack.
        }
        $base ??= 'Times';
        $bold = $command->fontWeight >= 600;
        $italic = str_contains($command->fontStyle, 'italic') || str_contains($command->fontStyle, 'oblique');
        return match ($base) {
            'Helvetica' => $bold && $italic ? 'Helvetica-BoldOblique' : ($bold ? 'Helvetica-Bold' : ($italic ? 'Helvetica-Oblique' : 'Helvetica')),
            'Courier' => $bold && $italic ? 'Courier-BoldOblique' : ($bold ? 'Courier-Bold' : ($italic ? 'Courier-Oblique' : 'Courier')),
            default => $bold && $italic ? 'Times-BoldItalic' : ($bold ? 'Times-Bold' : ($italic ? 'Times-Italic' : 'Times-Roman')),
        };
    }

    private function codePoints(string $text): array
    {
        $result = [];
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $b1 = ord($text[$i]);
            if ($b1 < 0x80) { $result[] = $b1; $i++; continue; }
            if (($b1 & 0xE0) === 0xC0 && $i + 1 < $length) {
                $b2 = ord($text[$i + 1]); if (($b2 & 0xC0) !== 0x80) throw new \LogicException('Invalid UTF-8 text cannot be serialized to PDF.');
                $result[] = (($b1 & 0x1F) << 6) | ($b2 & 0x3F); $i += 2; continue;
            }
            if (($b1 & 0xF0) === 0xE0 && $i + 2 < $length) {
                $b2 = ord($text[$i + 1]); $b3 = ord($text[$i + 2]);
                if (($b2 & 0xC0) !== 0x80 || ($b3 & 0xC0) !== 0x80) throw new \LogicException('Invalid UTF-8 text cannot be serialized to PDF.');
                $result[] = (($b1 & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F); $i += 3; continue;
            }
            if (($b1 & 0xF8) === 0xF0 && $i + 3 < $length) {
                $b2 = ord($text[$i + 1]); $b3 = ord($text[$i + 2]); $b4 = ord($text[$i + 3]);
                if (($b2 & 0xC0) !== 0x80 || ($b3 & 0xC0) !== 0x80 || ($b4 & 0xC0) !== 0x80) throw new \LogicException('Invalid UTF-8 text cannot be serialized to PDF.');
                $result[] = (($b1 & 0x07) << 18) | (($b2 & 0x3F) << 12) | (($b3 & 0x3F) << 6) | ($b4 & 0x3F); $i += 4; continue;
            }
            throw new \LogicException('Invalid UTF-8 text cannot be serialized to PDF.');
        }
        return $result;
    }

    /**
     * Encodes text for a WinAnsi-encoded Base14 font. A code point outside the WinAnsi
     * (CP1252) codepage is replaced with a literal `?`, mirroring pagyra-js's
     * encodeToWinAnsi(), instead of throwing and discarding the whole PDF: real-world
     * documents (Word-exported HTML in particular) routinely carry Unicode whitespace
     * variants such as U+2003 EM SPACE outside CP1252, and a single one of those should
     * not make the entire conversion fail.
     */
    private function encodeWinAnsi(string $text): string
    {
        $out = '';
        foreach ($this->codePoints($text) as $cp) {
            $out .= chr(WinAnsiEncoding::byteFor($cp) ?? WinAnsiEncoding::REPLACEMENT);
        }
        return $out;
    }

    private function escapePdfString(string $value): string
    {
        return strtr($value, ["\\"=>"\\\\",'('=>'\\(',')'=>'\\)',"\r"=>'\\r',"\n"=>'\\n',"\t"=>'\\t',"\b"=>'\\b',"\f"=>'\\f']);
    }

    private function assemble(array $objects, int $rootId): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) { $offsets[$id] = strlen($pdf); $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n"; }
        $xrefOffset = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
        $pdf .= "trailer\n<< /Size " . $size . ' /Root ' . $rootId . " 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
        return $pdf;
    }

    private function number(float $value): string
    {
        if (abs($value) < 0.0000001) return '0';
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
