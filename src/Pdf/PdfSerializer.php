<?php

declare(strict_types=1);

namespace Pagyra\Pdf;

use Pagyra\Paint\BoxPaintCommand;
use Pagyra\Paint\DisplayList;
use Pagyra\Paint\TextPaintCommand;
use Pagyra\Units\Units;

final class PdfSerializer
{
    public function serialize(DisplayList $displayList): string
    {
        $objects = [];
        $nextId = 1;
        $reserve = static function () use (&$objects, &$nextId): int {
            $id = $nextId++;
            $objects[$id] = '';
            return $id;
        };

        $catalogId = $reserve();
        $pagesId = $reserve();

        $fontIds = [];
        $fontNames = [];
        foreach ($displayList->pages as $page) {
            foreach ($page->commands as $command) {
                if (!$command instanceof TextPaintCommand) continue;
                $baseFont = $this->base14Font($command);
                if (isset($fontIds[$baseFont])) continue;
                $fontIds[$baseFont] = $reserve();
                $fontNames[$baseFont] = 'F' . count($fontNames + [$baseFont => true]);
            }
        }
        $fontIndex = 1;
        foreach ($fontIds as $baseFont => $fontId) {
            $fontNames[$baseFont] = 'F' . $fontIndex++;
            $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $baseFont . ' /Encoding /WinAnsiEncoding >>';
        }

        $pageIds = [];
        foreach ($displayList->pages as $page) {
            $content = '';
            $usedFonts = [];
            foreach ($page->commands as $command) {
                if ($command instanceof BoxPaintCommand) {
                    $content .= $this->serializeBox($command, $page->height);
                    continue;
                }
                if ($command instanceof TextPaintCommand) {
                    $baseFont = $this->base14Font($command);
                    $resourceName = $fontNames[$baseFont];
                    $usedFonts[$resourceName] = $fontIds[$baseFont];
                    $content .= $this->serializeText($command, $page->height, $resourceName);
                }
            }

            $contentId = $reserve();
            $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream";

            $pageId = $reserve();
            $pageIds[] = $pageId;
            $fontResources = '';
            foreach ($usedFonts as $resourceName => $fontId) {
                $fontResources .= '/' . $resourceName . ' ' . $fontId . ' 0 R ';
            }
            $resources = '<< /Font << ' . $fontResources . '>> >>';
            $widthPt = $this->number(Units::pxToPt($page->width));
            $heightPt = $this->number(Units::pxToPt($page->height));
            $objects[$pageId] = '<< /Type /Page /Parent ' . $pagesId . ' 0 R '
                . '/MediaBox [0 0 ' . $widthPt . ' ' . $heightPt . '] '
                . '/Resources ' . $resources . ' '
                . '/Contents ' . $contentId . ' 0 R >>';
        }

        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds));
        $objects[$pagesId] = '<< /Type /Pages /Count ' . count($pageIds) . ' /Kids [' . $kids . '] >>';
        $objects[$catalogId] = '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';

        return $this->assemble($objects, $catalogId);
    }

    private function serializeBox(BoxPaintCommand $command, float $pageHeightPx): string
    {
        $color = $command->backgroundColor;
        if ($color === null || $color->a <= 0.0 || $command->width <= 0.0 || $command->height <= 0.0) {
            return '';
        }

        $x = Units::pxToPt($command->x);
        $y = Units::pxToPt($pageHeightPx - $command->y - $command->height);
        $width = Units::pxToPt($command->width);
        $height = Units::pxToPt($command->height);
        [$r, $g, $b] = $color->toPdfRgb();

        return "q\n"
            . $this->number($r) . ' ' . $this->number($g) . ' ' . $this->number($b) . " rg\n"
            . $this->number($x) . ' ' . $this->number($y) . ' ' . $this->number($width) . ' ' . $this->number($height) . " re f\n"
            . "Q\n";
    }

    private function serializeText(TextPaintCommand $command, float $pageHeightPx, string $resourceName): string
    {
        $encoded = $this->encodeWinAnsi($command->text);
        $x = Units::pxToPt($command->x);
        $y = Units::pxToPt($pageHeightPx - $command->baseline);
        $fontSize = Units::pxToPt($command->fontSize);
        $color = $command->color;
        $r = $color?->toPdfRgb()[0] ?? 0.0;
        $g = $color?->toPdfRgb()[1] ?? 0.0;
        $b = $color?->toPdfRgb()[2] ?? 0.0;

        return "BT\n"
            . '/' . $resourceName . ' ' . $this->number($fontSize) . " Tf\n"
            . $this->number($r) . ' ' . $this->number($g) . ' ' . $this->number($b) . " rg\n"
            . '1 0 0 1 ' . $this->number($x) . ' ' . $this->number($y) . " Tm\n"
            . '(' . $this->escapePdfString($encoded) . ") Tj\n"
            . "ET\n";
    }

    private function base14Font(TextPaintCommand $command): string
    {
        $family = strtolower($command->fontFamily ?? 'times new roman');
        $first = trim(explode(',', $family, 2)[0], " \t\n\r\0\x0B\"'");
        if (str_contains($first, 'courier') || str_contains($first, 'mono')) {
            $base = 'Courier';
        } elseif (str_contains($first, 'helvetica') || str_contains($first, 'arial') || str_contains($first, 'sans')) {
            $base = 'Helvetica';
        } else {
            $base = 'Times';
        }

        $bold = $command->fontWeight >= 600;
        $italic = str_contains($command->fontStyle, 'italic') || str_contains($command->fontStyle, 'oblique');

        return match ($base) {
            'Helvetica' => $bold && $italic ? 'Helvetica-BoldOblique' : ($bold ? 'Helvetica-Bold' : ($italic ? 'Helvetica-Oblique' : 'Helvetica')),
            'Courier' => $bold && $italic ? 'Courier-BoldOblique' : ($bold ? 'Courier-Bold' : ($italic ? 'Courier-Oblique' : 'Courier')),
            default => $bold && $italic ? 'Times-BoldItalic' : ($bold ? 'Times-Bold' : ($italic ? 'Times-Italic' : 'Times-Roman')),
        };
    }

    private function encodeWinAnsi(string $text): string
    {
        $special = [
            0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84,
            0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88,
            0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
            0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93,
            0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
            0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
            0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
        ];

        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $b1 = ord($text[$i]);
            if ($b1 < 0x80) {
                $codePoint = $b1;
                $i++;
            } elseif (($b1 & 0xE0) === 0xC0 && $i + 1 < $length) {
                $b2 = ord($text[$i + 1]);
                $codePoint = (($b1 & 0x1F) << 6) | ($b2 & 0x3F);
                $i += 2;
            } elseif (($b1 & 0xF0) === 0xE0 && $i + 2 < $length) {
                $b2 = ord($text[$i + 1]);
                $b3 = ord($text[$i + 2]);
                $codePoint = (($b1 & 0x0F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
                $i += 3;
            } elseif (($b1 & 0xF8) === 0xF0 && $i + 3 < $length) {
                $b2 = ord($text[$i + 1]);
                $b3 = ord($text[$i + 2]);
                $b4 = ord($text[$i + 3]);
                $codePoint = (($b1 & 0x07) << 18) | (($b2 & 0x3F) << 12) | (($b3 & 0x3F) << 6) | ($b4 & 0x3F);
                $i += 4;
            } else {
                throw new \LogicException('Invalid UTF-8 text cannot be serialized to PDF.');
            }

            if ($codePoint <= 0x7F || ($codePoint >= 0xA0 && $codePoint <= 0xFF)) {
                $out .= chr($codePoint);
                continue;
            }
            if (isset($special[$codePoint])) {
                $out .= chr($special[$codePoint]);
                continue;
            }
            throw new \LogicException(sprintf('Character U+%04X is not supported by the current WinAnsi PDF text serializer.', $codePoint));
        }
        return $out;
    }

    private function escapePdfString(string $value): string
    {
        return strtr($value, [
            "\\" => "\\\\",
            '(' => '\\(',
            ')' => '\\)',
            "\r" => '\\r',
            "\n" => '\\n',
            "\t" => '\\t',
            "\b" => '\\b',
            "\f" => '\\f',
        ]);
    }

    /** @param array<int,string> $objects */
    private function assemble(array $objects, int $rootId): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $size . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . $size . ' /Root ' . $rootId . " 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";
        return $pdf;
    }

    private function number(float $value): string
    {
        if (abs($value) < 0.0000001) return '0';
        $formatted = number_format($value, 6, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
}
