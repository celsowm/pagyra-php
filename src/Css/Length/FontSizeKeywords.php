<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

/**
 * The CSS absolute-size and relative-size keywords for `font-size`, ported from the reference's
 * FONT_SIZE_KEYWORDS table (pagyra-js `src/css/parsers/dimension-parser.ts`).
 *
 * Two engines resolve `font-size` in this port — BlockLayoutEngine for block boxes and
 * InlineTextFormatter for inline runs — and neither knew these keywords, so every one of them
 * silently fell through to the inherited size in both directions: `x-small` inside a 13pt
 * paragraph stayed 13pt, and `medium` inside an 8px one stayed 8px. They are not a rarity in
 * real documents: 482 of the 2445 corpus files (19.7%) declare one, 13509 declarations in all,
 * and every single one of them is an inline `style=` attribute — the shape a browser emits when
 * text is pasted into the editor of a court system — so none of it is dead stylesheet.
 *
 * The ratios follow the reference exactly, including the two places it departs from what a
 * browser does: the keywords other than `medium` scale the *parent* font size rather than being
 * absolute against the base size, and `medium` alone is the fixed 16px. The reference has no
 * test pinning this, but AGENTS.md puts its implementation above the standard, so the port
 * matches it rather than Chromium.
 */
final class FontSizeKeywords
{
    /** The base font size `medium` resolves to, and the reference point the other ratios scale. */
    public const MEDIUM_PX = 16.0;

    private const RATIOS = [
        'xx-small' => 0.6,
        'x-small' => 0.75,
        'small' => 0.89,
        'medium' => 1.0,
        'large' => 1.2,
        'x-large' => 1.5,
        'xx-large' => 2.0,
        'xxx-large' => 3.0,
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
        if ($normalized === 'medium') {
            return self::MEDIUM_PX;
        }

        $ratio = self::RATIOS[$normalized] ?? null;

        return $ratio === null ? null : max(0.0, $ratio * $parentFontSize);
    }
}
