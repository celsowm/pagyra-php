<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

use Pagyra\Fonts\Base14\Base14WidthTable;
use Pagyra\Style\ComputedStyle;
use Pagyra\Units\Units;

final class HeuristicTextMetrics implements TextMetrics
{
    private const ROOT_FONT_SIZE = 16.0;
    private const WIDTH_CALIBRATION = 0.9;
    private const SPACE = 0.32;
    private const DIGIT = 0.52;
    private const UPPER = 0.58;
    private const BASE = 0.5;
    private const PUNCTUATION = 0.35;
    private const IDEOGRAPHIC = 1.0;

    public function measure(string $text, ComputedStyle $style, float $fontSize): TextMeasurement
    {
        $lines = preg_split('/\r?\n/u', $text) ?: [''];
        $maxLineWidth = 0.0;
        $maxWordWidth = 0.0;

        foreach ($lines as $line) {
            $maxLineWidth = max($maxLineWidth, $this->measureLine($line, $style, $fontSize));
            foreach (preg_split('/\s+/u', $line) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $maxWordWidth = max($maxWordWidth, $this->measureLine($word, $style, $fontSize));
            }
        }

        $lineHeight = $this->lineHeight($style, $fontSize);
        return new TextMeasurement(
            inlineSize: $maxLineWidth,
            minInlineSize: $maxWordWidth,
            blockSize: max($lineHeight, count($lines) * $lineHeight),
        );
    }

    /**
     * `line-height` accepts a unitless factor, a percentage or any CSS length. Only the first
     * three forms were recognised, so every other unit silently fell back to `font-size * 1.2`:
     * the eproc/TJRJ templates set `line-height: 11pt` (and 8pt, 9pt, 15pt) thousands of times,
     * and a `font-size: 8pt; line-height: 8pt` block came out 20% too tall, which is most of the
     * "leading is bigger than wkhtmltopdf's" gap. `em` resolves against the element's own size.
     */
    public function lineHeight(ComputedStyle $style, float $fontSize): float
    {
        $value = strtolower(trim($style->get('line-height', 'normal') ?? 'normal'));
        $value = trim(preg_replace('/!\s*important$/', '', $value) ?? $value);
        if ($value === '' || $value === 'normal') {
            return $fontSize * 1.2;
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) === 1) {
            return max(0.0, (float) $value * $fontSize);
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $value, $m) === 1) {
            return max(0.0, ((float) $m[1] / 100.0) * $fontSize);
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)(px|pt|em|rem|cm|mm|q|in|pc)$/', $value, $m) === 1) {
            $n = (float) $m[1];
            return max(0.0, match ($m[2]) {
                'px' => $n,
                'pt' => Units::ptToPx($n),
                'cm' => Units::cmToPx($n),
                'mm' => Units::mmToPx($n),
                'q' => Units::qToPx($n),
                'in' => Units::inToPx($n),
                'pc' => Units::pcToPx($n),
                'em' => $n * $fontSize,
                'rem' => $n * self::ROOT_FONT_SIZE,
            });
        }
        return $fontSize * 1.2;
    }

    private function measureLine(string $line, ComputedStyle $style, float $fontSize): float
    {
        if ($line === '') {
            return 0.0;
        }

        $fontFamily = $style->get('font-family', '') ?? '';
        $isMonospace = preg_match('/(mono|code|courier|console)/i', $fontFamily) === 1;
        $baseFactor = $isMonospace ? 0.6 : self::BASE;
        $weightMultiplier = $this->weightMultiplier($style->get('font-weight'));
        $letterSpacing = $this->parseSpacing($style->get('letter-spacing'));
        $wordSpacing = $this->parseSpacing($style->get('word-spacing'));

        $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $spaces = substr_count($line, ' ');
        $spacing = max(count($chars) - 1, 0) * $letterSpacing + $spaces * $wordSpacing;

        // Real advance widths win whenever the text will be drawn with a standard font, so the
        // measured run matches what the viewer lays down. Only when the family maps to no Base14
        // font, or the text uses a character outside the table, does the estimate below apply.
        $base14 = Base14WidthTable::measure($line, $style, $fontSize);
        if ($base14 !== null) {
            return $base14 + $spacing;
        }

        $factor = 0.0;
        $spaces = 0;
        foreach ($chars as $char) {
            if ($char === ' ') {
                $spaces++;
                $factor += self::SPACE;
            } elseif ($char === "\t") {
                $factor += self::SPACE * 4;
            } elseif (preg_match('/^[0-9]$/u', $char) === 1) {
                $factor += self::DIGIT;
            } elseif (preg_match('/^[A-Z]$/u', $char) === 1) {
                $factor += $baseFactor + (self::UPPER - self::BASE);
            } elseif (preg_match('/^[.,;:!?\'"`~\-_\/\\()\[\]{}<>]$/u', $char) === 1) {
                $factor += self::PUNCTUATION;
            } elseif ($this->isIdeographic($char)) {
                $factor += self::IDEOGRAPHIC;
            } else {
                $factor += $baseFactor;
            }
        }

        return $factor * $fontSize * $weightMultiplier * self::WIDTH_CALIBRATION + $spacing;
    }

    private function weightMultiplier(?string $weight): float
    {
        if ($weight === null || trim($weight) === '') {
            return 1.0;
        }
        $normalized = strtolower(trim($weight));
        $numeric = match ($normalized) {
            'normal' => 400.0,
            'bold' => 700.0,
            default => is_numeric($normalized) ? (float) $normalized : 400.0,
        };
        return 1.0 + max(0.0, min(900.0, $numeric) - 400.0) / 5000.0;
    }

    private function parseSpacing(?string $value): float
    {
        if ($value === null || strtolower(trim($value)) === 'normal') {
            return 0.0;
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', trim($value), $m) === 1) {
            return (float) $m[1];
        }
        return 0.0;
    }

    private function isIdeographic(string $char): bool
    {
        if (preg_match('/^\p{Han}$/u', $char) === 1) return true;
        if (preg_match('/^\p{Hiragana}$/u', $char) === 1) return true;
        if (preg_match('/^\p{Katakana}$/u', $char) === 1) return true;
        return preg_match('/^\p{Hangul}$/u', $char) === 1;
    }
}
