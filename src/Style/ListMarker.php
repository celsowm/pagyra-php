<?php

declare(strict_types=1);

namespace Pagyra\Style;

/**
 * CSS list marker strings, ported from pagyra-js's `pdf/utils/list-utils.ts`
 * (`formatListMarker` / `resolveListStyleType`) plus its `list-style-parser.ts`
 * keyword map. Only the marker text is produced here; positioning lives in
 * DisplayListBuilder and the counter is a simple sibling count, matching the
 * reference (with `<ol start>` / `<li value>` honoured on top of it).
 */
final class ListMarker
{
    private const KEYWORD_MAP = [
        'none' => 'none',
        'disc' => 'disc',
        'circle' => 'circle',
        'square' => 'square',
        'decimal' => 'decimal',
        'decimal-leading-zero' => 'decimal-leading-zero',
        'lower-alpha' => 'lower-alpha',
        'lower-latin' => 'lower-alpha',
        'upper-alpha' => 'upper-alpha',
        'upper-latin' => 'upper-alpha',
        'lower-roman' => 'lower-roman',
        'upper-roman' => 'upper-roman',
    ];

    private const BULLETS = [
        'disc' => "\u{2022}",   // •
        'circle' => "\u{25E6}", // ◦
        'square' => "\u{25AA}", // ▪
    ];

    private const ORDERED = [
        'decimal', 'decimal-leading-zero', 'lower-alpha', 'upper-alpha', 'lower-roman', 'upper-roman',
    ];

    public static function normalizeType(?string $value): ?string
    {
        if ($value === null) return null;
        $normalized = strtolower(trim($value));
        if ($normalized === '') return null;
        if ($normalized === 'initial') return 'disc';
        if (in_array($normalized, ['inherit', 'unset', 'revert', 'revert-layer'], true)) return null;
        return self::KEYWORD_MAP[$normalized] ?? $normalized;
    }

    /**
     * Mirrors `resolveListStyleType`: the item's own type wins unless it is the
     * plain default `disc`, otherwise the parent list's type, otherwise
     * `<ol>` => decimal / `<ul>` => disc.
     */
    public static function resolveType(?string $own, ?string $parentType, string $parentTag): ?string
    {
        if ($own === 'none') return 'none';
        if ($own !== null && $own !== 'disc') return $own;
        if ($parentType === 'none') return 'none';
        if ($parentType !== null) return $parentType;
        if ($parentTag === 'ol') return 'decimal';
        if ($parentTag === 'ul') return 'disc';
        return $own ?? 'disc';
    }

    public static function isOrdered(string $type): bool
    {
        return in_array($type, self::ORDERED, true);
    }

    public static function format(string $type, int $index): ?string
    {
        return match ($type) {
            'none' => null,
            'decimal' => $index . '.',
            'decimal-leading-zero' => str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '.',
            'lower-alpha' => strtolower(self::alphaSequence($index)) . '.',
            'upper-alpha' => strtoupper(self::alphaSequence($index)) . '.',
            'lower-roman' => strtolower(self::roman($index) ?? (string) $index) . '.',
            'upper-roman' => strtoupper(self::roman($index) ?? (string) $index) . '.',
            'disc', 'circle', 'square' => self::BULLETS[$type],
            default => self::BULLETS['disc'],
        };
    }

    private static function alphaSequence(int $index): string
    {
        $n = max(1, $index);
        $result = '';
        while ($n > 0) {
            $n--;
            $result = chr(65 + ($n % 26)) . $result;
            $n = intdiv($n, 26);
        }
        return $result;
    }

    private static function roman(int $index): ?string
    {
        if ($index <= 0 || $index >= 4000) return null;
        $pairs = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
            50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];
        $remainder = $index;
        $result = '';
        foreach ($pairs as $value => $numeral) {
            while ($remainder >= $value) {
                $result .= $numeral;
                $remainder -= $value;
            }
        }
        return $result;
    }
}
