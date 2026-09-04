<?php

declare(strict_types=1);

namespace Pagyra\Fonts\Base14;

use Pagyra\Fonts\WinAnsiEncoding;
use Pagyra\Style\ComputedStyle;

/**
 * Advance-width lookup for the standard fonts, following the reference's `measureUsingBase14()`
 * and `resolveBase14Font()` in `src/layout/utils/text-metrics.ts`.
 *
 * This matters because whatever the layout measures has to match what the PDF viewer actually
 * draws. With no embeddable TrueType face configured, the serializer falls back to a Base14
 * font and the viewer then advances by that font's real per-glyph widths. Measuring the same
 * text with a per-character heuristic instead puts every run at the wrong x: the estimate is
 * nearly uniform per character, so a run of capitals comes out far too narrow and the next run
 * is drawn on top of it, while narrow glyphs leave visible gaps.
 *
 * Two deliberate differences from the reference's tables:
 *
 * - they are keyed by the WinAnsi byte the serializer writes, not by StandardEncoding. The
 *   reference's own tables are StandardEncoding above 0x7F while its serializer emits WinAnsi,
 *   so there they disagree exactly where they matter for accented text: `234` is `ecircumflex`
 *   (444 in Times-Roman) under WinAnsi but `OE` (889) under StandardEncoding.
 * - italic/oblique variants are included, because this port's serializer does select
 *   `Times-Italic` and friends, and their widths differ from the upright face (Times-Italic `M`
 *   is 833, Times-Roman's is 889).
 */
final class Base14WidthTable
{
    private const BOLD_THRESHOLD = 600.0;

    /** @var array<string,array<int,int>>|null */
    private static ?array $tables = null;

    /**
     * Width of one line in the resolved Base14 font, or null when the text uses a character the
     * width table cannot carry, in which case the caller keeps its own estimate. Letter/word
     * spacing is the caller's business, as in the reference.
     */
    public static function measure(string $text, ComputedStyle $style, float $fontSize): ?float
    {
        $widths = self::tables()[self::resolveFont($style)] ?? null;
        if ($widths === null) return null;

        $total = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $code = self::codePoint($char);
            if ($code === null) return null;
            $byte = WinAnsiEncoding::byteFor($code);
            if ($byte === null) {
                // Outside the codepage: the serializer writes `?`, so measure that same glyph
                // instead of abandoning the whole line to the estimate.
                $byte = WinAnsiEncoding::REPLACEMENT;
            }
            $width = $widths[$byte] ?? null;
            if ($width === null) return null;
            $total += $width;
        }

        return ($total / 1000.0) * $fontSize;
    }

    /**
     * Base14 face for a computed style, resolved exactly the way PdfSerializer::base14Font()
     * resolves the face it will actually draw with — same substring matching, same order, same
     * final fallback to Times. The two must agree glyph for glyph: the measurement decides where
     * the layout puts the *next* run, while the serializer decides how wide this one is really
     * drawn, so any disagreement shows up as text drawn on top of the run that follows it.
     *
     * Both earlier mismatches came from this method being stricter than the serializer. It used
     * to match family names by exact equality against an alias table, so `Arial Narrow` fell
     * through here while the serializer matched `arial`; and it returned null when nothing in
     * the stack matched, sending the caller to a rough per-character estimate while the
     * serializer went right on drawing in Times. That second case is why `<b>` text with no
     * `font-family` of its own — the operative paragraph of a sentença, "JULGO EXTINTO O
     * PROCESSO…" — was measured narrower than Times-Bold actually draws it and overlapped the
     * text that followed.
     */
    public static function resolveFont(ComputedStyle $style): string
    {
        $bold = self::normalizedWeight($style->get('font-weight')) >= self::BOLD_THRESHOLD;
        $italic = in_array(strtolower(trim($style->get('font-style', 'normal') ?? 'normal')), ['italic', 'oblique'], true);

        $family = null;
        foreach (explode(',', strtolower($style->get('font-family', '') ?? '')) as $token) {
            $token = trim($token, " \t\n\r\0\x0B\"'");
            if ($token === '') continue;
            if (str_contains($token, 'courier') || str_contains($token, 'mono')) { $family = 'Courier'; break; }
            if (str_contains($token, 'helvetica') || str_contains($token, 'arial') || str_contains($token, 'sans')) { $family = 'Helvetica'; break; }
            if (str_contains($token, 'times') || str_contains($token, 'georgia') || str_contains($token, 'serif')) { $family = 'Times'; break; }
            // Unknown family name — keep looking at the next fallback in the stack.
        }
        $family ??= 'Times';

        return match ($family) {
            'Helvetica' => $bold && $italic ? 'Helvetica-BoldOblique' : ($bold ? 'Helvetica-Bold' : ($italic ? 'Helvetica-Oblique' : 'Helvetica')),
            'Courier' => $bold && $italic ? 'Courier-BoldOblique' : ($bold ? 'Courier-Bold' : ($italic ? 'Courier-Oblique' : 'Courier')),
            default => $bold && $italic ? 'Times-BoldItalic' : ($bold ? 'Times-Bold' : ($italic ? 'Times-Italic' : 'Times-Roman')),
        };
    }

    /** Decodes one UTF-8 character without requiring ext-mbstring, which this package does not. */
    private static function codePoint(string $char): ?int
    {
        $bytes = array_values(unpack('C*', $char) ?: []);
        $length = count($bytes);
        if ($length === 0) return null;
        if ($length === 1) return $bytes[0] < 0x80 ? $bytes[0] : null;
        if ($length === 2 && ($bytes[0] & 0xE0) === 0xC0) {
            return (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F);
        }
        if ($length === 3 && ($bytes[0] & 0xF0) === 0xE0) {
            return (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F);
        }
        if ($length === 4 && ($bytes[0] & 0xF8) === 0xF0) {
            return (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F);
        }
        return null;
    }

    private static function normalizedWeight(?string $weight): float
    {
        $normalized = strtolower(trim($weight ?? ''));
        return match ($normalized) {
            '', 'normal' => 400.0,
            'bold', 'bolder' => 700.0,
            'lighter' => 300.0,
            default => is_numeric($normalized) ? (float) $normalized : 400.0,
        };
    }

    /** @return array<string,array<int,int>> */
    private static function tables(): array
    {
        return self::$tables ??= [
            'Times-Roman' => TimesRomanWidths::WIDTHS,
            'Times-Bold' => TimesBoldWidths::WIDTHS,
            'Times-Italic' => TimesItalicWidths::WIDTHS,
            'Times-BoldItalic' => TimesBoldItalicWidths::WIDTHS,
            'Helvetica' => HelveticaWidths::WIDTHS,
            'Helvetica-Bold' => HelveticaBoldWidths::WIDTHS,
            'Helvetica-Oblique' => HelveticaObliqueWidths::WIDTHS,
            'Helvetica-BoldOblique' => HelveticaBoldObliqueWidths::WIDTHS,
            'Courier' => CourierWidths::WIDTHS,
            'Courier-Bold' => CourierBoldWidths::WIDTHS,
            'Courier-Oblique' => CourierObliqueWidths::WIDTHS,
            'Courier-BoldOblique' => CourierBoldObliqueWidths::WIDTHS,
        ];
    }
}
