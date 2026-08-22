<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

use Pagyra\Style\ComputedStyle;

final class GlyphTextMetrics implements TextMetrics
{
    public function __construct(
        private readonly FontRegistry $registry,
        private readonly TextMetrics $fallback = new HeuristicTextMetrics(),
    ) {
    }

    public function measure(string $text, ComputedStyle $style, float $fontSize): TextMeasurement
    {
        $metrics = $this->registry->resolve(
            $style->get('font-family'),
            $this->fontWeight($style->get('font-weight')),
            $style->get('font-style', 'normal') ?? 'normal',
        );
        if ($metrics === null) return $this->fallback->measure($text, $style, $fontSize);

        $lines = preg_split('/\r?\n/u', $text) ?: [''];
        $maxLine = 0.0;
        $maxWord = 0.0;
        foreach ($lines as $line) {
            $maxLine = max($maxLine, $this->measureLine($line, $metrics, $style, $fontSize));
            foreach (preg_split('/\s+/u', $line) ?: [] as $word) {
                if ($word !== '') $maxWord = max($maxWord, $this->measureLine($word, $metrics, $style, $fontSize));
            }
        }
        $lineHeight = $this->lineHeight($style, $fontSize);
        return new TextMeasurement($maxLine, $maxWord, max($lineHeight, count($lines) * $lineHeight));
    }

    public function lineHeight(ComputedStyle $style, float $fontSize): float
    {
        return $this->fallback->lineHeight($style, $fontSize);
    }

    private function measureLine(string $text, \Pagyra\Fonts\Ttf\TtfFontMetrics $metrics, ComputedStyle $style, float $fontSize): float
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $units = 0;
        $previous = null;
        foreach ($chars as $char) {
            $gid = $metrics->glyphId($this->codePoint($char));
            if ($previous !== null) $units += $metrics->kerning($previous, $gid);
            $units += $metrics->advanceWidth($gid);
            $previous = $gid;
        }

        $letterSpacing = $this->pxSpacing($style->get('letter-spacing'));
        $wordSpacing = $this->pxSpacing($style->get('word-spacing'));
        $spaces = substr_count($text, ' ');
        $spacing = max(count($chars) - 1, 0) * $letterSpacing + $spaces * $wordSpacing;
        return ($units / $metrics->unitsPerEm) * $fontSize + $spacing;
    }

    private function codePoint(string $char): int
    {
        $bytes = array_values(unpack('C*', $char));
        $b0 = $bytes[0] ?? 0;
        if ($b0 < 0x80) return $b0;
        if (($b0 & 0xE0) === 0xC0) return (($b0 & 0x1F) << 6) | (($bytes[1] ?? 0) & 0x3F);
        if (($b0 & 0xF0) === 0xE0) return (($b0 & 0x0F) << 12) | ((($bytes[1] ?? 0) & 0x3F) << 6) | (($bytes[2] ?? 0) & 0x3F);
        return (($b0 & 0x07) << 18) | ((($bytes[1] ?? 0) & 0x3F) << 12) | ((($bytes[2] ?? 0) & 0x3F) << 6) | (($bytes[3] ?? 0) & 0x3F);
    }

    private function fontWeight(?string $value): int
    {
        $value = strtolower(trim($value ?? '400'));
        return match ($value) {
            'normal' => 400,
            'bold' => 700,
            default => is_numeric($value) ? (int) $value : 400,
        };
    }

    private function pxSpacing(?string $value): float
    {
        if ($value !== null && preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $m) === 1) return (float) $m[1];
        return 0.0;
    }
}
