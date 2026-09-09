<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

/**
 * The CSS absolute-size and relative-size keywords for `font-size`, ratios ported from the
 * reference's FONT_SIZE_KEYWORDS table (pagyra-js `src/css/parsers/dimension-parser.ts`).
 *
 * Two engines resolve `font-size` in this port — BlockLayoutEngine for block boxes and
 * InlineTextFormatter for inline runs — and neither knew these keywords, so every one of them
 * silently fell through to the inherited size in both directions: `x-small` inside a 13pt
 * paragraph stayed 13pt, and `medium` inside an 8px one stayed 8px. They are not a rarity in
 * real documents: 482 of the 2445 corpus files (19.7%) declare one, 13509 declarations in all,
 * and every single one of them is an inline `style=` attribute — the shape a browser emits when
 * text is pasted into the editor of a court system — so none of it is dead stylesheet.
 *
 * CSS Fonts distinguishes two kinds of keyword here, and the two resolve differently:
 *
 *  - `xx-small` .. `xxx-large` are *absolute* size keywords. Each looks up a fixed ratio against
 *    `medium`, which is itself a constant (this port pins it at 16px, the common UA default) —
 *    not against the element's inherited font-size. `large` means 1.2 x 16px full stop, whether
 *    it sits on a root element or three `x-large` ancestors deep.
 *  - only `smaller`/`larger` are *relative* size keywords, and those do scale the parent's
 *    computed font-size, the one behavior the absolute keywords are so often mistaken for.
 *
 * The reference (`relativeLength("em", keywordRatio)` for every keyword except `medium`) folds
 * both into one relative rule, so a doc-pasted `<span style="font-size:large"><span
 * style="font-size:x-large">` compounds there: 1.2 x 1.5 = 1.8x the surrounding text instead of
 * x-large's own fixed 1.5x. There is no pagyra-js test pinning this, and it does not match
 * WebKit — checked directly against wkhtmltopdf, the parity target this port is measured
 * against: `x-large` renders pixel-identical whether it is bare or wrapped in `large`, and
 * `large` alone renders identical at every parent font-size tried, 8pt through 16px. Item 5 of
 * AGENTS.md backs departing from the reference here, the same way this port already resolves
 * other keywords the reference gets wrong; pagyra-js should get the same fix upstream. Sample in
 * the corpus: EPROC1/PJE1 letterheads nest exactly this pattern in their title line, and it came
 * out oversized and overlapping the line below it before this fix.
 */
final class FontSizeKeywords
{
    /** The base font size `medium` resolves to, and the reference point the absolute ratios scale. */
    public const MEDIUM_PX = 16.0;

    private const ABSOLUTE_RATIOS = [
        'xx-small' => 0.6,
        'x-small' => 0.75,
        'small' => 0.89,
        'medium' => 1.0,
        'large' => 1.2,
        'x-large' => 1.5,
        'xx-large' => 2.0,
        'xxx-large' => 3.0,
    ];

    private const RELATIVE_RATIOS = [
        'smaller' => 0.8,
        'larger' => 1.2,
    ];

    /** The keyword's used font size in px, or null when the value is not one of these keywords. */
    public static function resolve(string $value, float $parentFontSize): ?float
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        $absolute = self::ABSOLUTE_RATIOS[$normalized] ?? null;
        if ($absolute !== null) {
            return max(0.0, $absolute * self::MEDIUM_PX);
        }

        $relative = self::RELATIVE_RATIOS[$normalized] ?? null;

        return $relative === null ? null : max(0.0, $relative * $parentFontSize);
    }
}
