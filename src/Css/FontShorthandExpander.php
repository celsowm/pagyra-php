<?php

declare(strict_types=1);

namespace Pagyra\Css;

/**
 * Expands the `font` shorthand (CSS Fonts 4 §2.8) into `font-style`, `font-variant`,
 * `font-weight`, `font-stretch`, `font-size`, `line-height` and `font-family`:
 *
 *     [ <style> || <variant-css2> || <weight> || <stretch-css3> ]? <size> [ / <line-height> ]? <family>
 *
 * It was stored as an unknown property named `font`, so `font: bold 12pt/1.5 Arial` changed
 * nothing at all. Every longhand the shorthand does not mention is reset to its initial value, as
 * the spec says, and a CSS-wide keyword (`font: inherit`, which pasted editor HTML is full of) is
 * handed to every longhand. System fonts (`caption`, `menu`...) and anything that does not parse
 * are left unexpanded, which keeps the old behaviour of ignoring them.
 */
final class FontShorthandExpander
{
    private const STYLES = ['italic', 'oblique'];
    private const VARIANTS = ['small-caps'];
    private const WEIGHTS = ['bold', 'bolder', 'lighter'];
    private const STRETCHES = [
        'ultra-condensed', 'extra-condensed', 'condensed', 'semi-condensed',
        'semi-expanded', 'expanded', 'extra-expanded', 'ultra-expanded',
    ];
    private const SIZE_KEYWORDS = [
        'xx-small', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large', 'larger', 'smaller',
    ];
    private const WIDE_KEYWORDS = ['inherit', 'initial', 'unset', 'revert', 'revert-layer'];

    /** @return array<string,string>|null */
    public function expand(string $property, string $value): ?array
    {
        if ($property !== 'font') {
            return null;
        }
        $value = trim($value);
        $keyword = strtolower($value);
        if (in_array($keyword, self::WIDE_KEYWORDS, true)) {
            return array_fill_keys(
                ['font-style', 'font-variant', 'font-weight', 'font-stretch', 'font-size', 'line-height', 'font-family'],
                $keyword,
            );
        }

        $expanded = [
            'font-style' => 'normal',
            'font-variant' => 'normal',
            'font-weight' => 'normal',
            'font-stretch' => 'normal',
            'line-height' => 'normal',
        ];
        $rest = $value;
        $prefixCount = 0;
        while ($rest !== '' && $prefixCount < 4) {
            if (preg_match('/^(\S+)\s+/', $rest, $m) !== 1) {
                break;
            }
            $token = strtolower($m[1]);
            if ($token === 'normal') {
                // Resets whichever of the four it stands for, which is what all of them are already.
            } elseif (in_array($token, self::STYLES, true)) {
                $expanded['font-style'] = $token;
            } elseif (in_array($token, self::VARIANTS, true)) {
                $expanded['font-variant'] = $token;
            } elseif (in_array($token, self::WEIGHTS, true) || preg_match('/^[1-9]00$/', $token) === 1) {
                $expanded['font-weight'] = $token;
            } elseif (in_array($token, self::STRETCHES, true)) {
                $expanded['font-stretch'] = $token;
            } else {
                break;
            }
            $rest = substr($rest, strlen($m[0]));
            $prefixCount++;
        }

        $size = '(?:\d*\.?\d+(?:px|pt|pc|in|cm|mm|q|em|rem|ex|ch|vw|vh|vmin|vmax|%)?|' . implode('|', self::SIZE_KEYWORDS) . ')';
        if (preg_match('~^(' . $size . ')\s*(?:/\s*(\S+))?\s+(.+)$~i', $rest, $m) !== 1) {
            return null;
        }
        $family = trim($m[3]);
        if ($family === '' || preg_match('/^[\d.]/', $family) === 1) {
            return null;
        }
        $expanded['font-size'] = strtolower($m[1]);
        if (($m[2] ?? '') !== '') {
            $expanded['line-height'] = $m[2];
        }
        $expanded['font-family'] = $family;

        return $expanded;
    }
}
