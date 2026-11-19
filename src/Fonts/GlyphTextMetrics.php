<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

use Pagyra\Fonts\Ttf\TtfFontMetrics;
use Pagyra\Style\ComputedStyle;

final class GlyphTextMetrics implements TextMetrics
{
    public function __construct(
        private readonly FontRegistry $registry,
        private readonly TextMetrics $fallback = new HeuristicTextMetrics(),
    ) {
    }

    /** Limite de medidas guardadas por instancia (uma instancia por renderizacao). */
    private const CACHE_MAX_ENTRIES = 50000;

    /**
     * Medidas ja calculadas nesta renderizacao. Layout, medida intrinseca e quebra de linha medem
     * as mesmas palavras muitas vezes (uma fatura de 150 linhas repete "Webentwicklung" em cada
     * celula, e cada celula e medida para o min/max-content e de novo para o layout), e cada
     * medida resolvia a pilha de fontes caractere a caractere. A chave tem tudo o que a medida
     * le do estilo: familia, peso, estilo, letter-spacing, word-spacing e line-height.
     *
     * @var array<string,TextMeasurement>
     */
    private array $cache = [];

    public function measure(string $text, ComputedStyle $style, float $fontSize): TextMeasurement
    {
        $key = $fontSize . "\0" . $style->get('font-family') . "\0" . $style->get('font-weight') . "\0"
            . $style->get('font-style') . "\0" . $style->get('letter-spacing') . "\0" . $style->get('word-spacing') . "\0"
            . $style->get('line-height') . "\0" . $text;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        if (count($this->cache) >= self::CACHE_MAX_ENTRIES) {
            $this->cache = [];
        }

        return $this->cache[$key] = $this->measureUncached($text, $style, $fontSize);
    }

    private function measureUncached(string $text, ComputedStyle $style, float $fontSize): TextMeasurement
    {
        $lines = preg_split('/\r?\n/u', $text) ?: [''];
        $maxLine = 0.0;
        $maxWord = 0.0;
        foreach ($lines as $line) {
            $maxLine = max($maxLine, $this->measureLine($line, $style, $fontSize));
            foreach (preg_split('/\s+/u', $line) ?: [] as $word) {
                if ($word !== '') $maxWord = max($maxWord, $this->measureLine($word, $style, $fontSize));
            }
        }
        $lineHeight = $this->lineHeight($style, $fontSize);
        return new TextMeasurement($maxLine, $maxWord, max($lineHeight, count($lines) * $lineHeight));
    }

    public function lineHeight(ComputedStyle $style, float $fontSize): float
    {
        return $this->fallback->lineHeight($style, $fontSize);
    }

    private function measureLine(string $text, ComputedStyle $style, float $fontSize): float
    {
        if ($text === '') return 0.0;

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $weight = $this->fontWeight($style->get('font-weight'));
        $fontStyle = $style->get('font-style', 'normal') ?? 'normal';
        $family = $style->get('font-family');
        $width = 0.0;
        $fallbackBuffer = '';
        $previousFace = null;
        $previousGlyph = null;

        $flushFallback = function () use (&$fallbackBuffer, &$width, $style, $fontSize): void {
            if ($fallbackBuffer === '') return;
            $properties = $style->properties;
            unset($properties['letter-spacing'], $properties['word-spacing']);
            $plainStyle = new ComputedStyle($properties);
            $width += $this->fallback->measure($fallbackBuffer, $plainStyle, $fontSize)->inlineSize;
            $fallbackBuffer = '';
        };

        foreach ($chars as $char) {
            $codePoint = $this->codePoint($char);
            $face = $this->registry->resolveFaceForCodePoint($family, $codePoint, $weight, $fontStyle);

            if ($face === null) {
                $fallbackBuffer .= $char;
                $previousFace = null;
                $previousGlyph = null;
                continue;
            }

            $flushFallback();
            $glyph = $face->metrics->glyphId($codePoint);
            if ($previousFace === $face && $previousGlyph !== null) {
                $width += ($face->metrics->kerning($previousGlyph, $glyph) / $face->metrics->unitsPerEm) * $fontSize;
            }
            $width += ($face->metrics->advanceWidth($glyph) / $face->metrics->unitsPerEm) * $fontSize;
            $previousFace = $face;
            $previousGlyph = $glyph;
        }
        $flushFallback();

        $letterSpacing = $this->pxSpacing($style->get('letter-spacing'));
        $wordSpacing = $this->pxSpacing($style->get('word-spacing'));
        $spaces = substr_count($text, ' ');
        $width += max(count($chars) - 1, 0) * $letterSpacing + $spaces * $wordSpacing;

        return $width;
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
