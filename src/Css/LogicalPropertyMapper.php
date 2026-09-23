<?php

declare(strict_types=1);

namespace Pagyra\Css;

/**
 * Maps the CSS Logical Properties (Level 1) onto the physical ones for the only writing mode this
 * port lays out, horizontal-tb left-to-right: inline-start is left, inline-end right, block-start
 * top and block-end bottom. `inset` and its logical forms map onto top/right/bottom/left.
 *
 * None of them was known, so `margin-inline: auto`, `padding-block: 1em`, `border-inline-start:
 * 3px solid` or `inline-size: 50%` — which current editors and CSS frameworks emit — did nothing.
 * The mapped declarations then go through the usual shorthand expansion, so `border-block-end`
 * becomes `border-bottom` and from there its three longhands.
 */
final class LogicalPropertyMapper
{
    private const DIRECT = [
        'margin-inline-start' => 'margin-left', 'margin-inline-end' => 'margin-right',
        'margin-block-start' => 'margin-top', 'margin-block-end' => 'margin-bottom',
        'padding-inline-start' => 'padding-left', 'padding-inline-end' => 'padding-right',
        'padding-block-start' => 'padding-top', 'padding-block-end' => 'padding-bottom',
        'border-inline-start' => 'border-left', 'border-inline-end' => 'border-right',
        'border-block-start' => 'border-top', 'border-block-end' => 'border-bottom',
        'inset-inline-start' => 'left', 'inset-inline-end' => 'right',
        'inset-block-start' => 'top', 'inset-block-end' => 'bottom',
        'inline-size' => 'width', 'block-size' => 'height',
        'min-inline-size' => 'min-width', 'min-block-size' => 'min-height',
        'max-inline-size' => 'max-width', 'max-block-size' => 'max-height',
        'border-start-start-radius' => 'border-top-left-radius', 'border-start-end-radius' => 'border-top-right-radius',
        'border-end-start-radius' => 'border-bottom-left-radius', 'border-end-end-radius' => 'border-bottom-right-radius',
    ];

    /** Two-value logical shorthands: the first value is the start side, the second the end side. */
    private const PAIRS = [
        'margin-inline' => ['margin-left', 'margin-right'],
        'margin-block' => ['margin-top', 'margin-bottom'],
        'padding-inline' => ['padding-left', 'padding-right'],
        'padding-block' => ['padding-top', 'padding-bottom'],
        'inset-inline' => ['left', 'right'],
        'inset-block' => ['top', 'bottom'],
    ];

    /**
     * The physical declarations a logical one stands for, or null when the property is not
     * logical. A `border-inline-*`/`border-block-*` longhand (`-width`, `-style`, `-color`) maps
     * to the physical side's longhand.
     *
     * @return array<string,string>|null
     */
    public function map(string $property, string $value): ?array
    {
        if (isset(self::DIRECT[$property])) {
            return [self::DIRECT[$property] => $value];
        }
        if (isset(self::PAIRS[$property])) {
            $parts = self::split($value);
            if ($parts === [] || count($parts) > 2) return [];
            [$start, $end] = self::PAIRS[$property];

            return [$start => $parts[0], $end => $parts[1] ?? $parts[0]];
        }
        if ($property === 'inset') {
            $parts = self::split($value);
            if ($parts === [] || count($parts) > 4) return [];
            [$top, $right, $bottom, $left] = match (count($parts)) {
                1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
                2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
                3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
                default => $parts,
            };

            return ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left];
        }
        if (preg_match('/^border-(inline|block)(?:-(start|end))?(?:-(width|style|color))?$/', $property, $m) === 1) {
            $sides = $m[1] === 'inline' ? ['left', 'right'] : ['top', 'bottom'];
            $sides = match ($m[2] ?? '') {
                'start' => [$sides[0]],
                'end' => [$sides[1]],
                default => $sides,
            };
            $suffix = ($m[3] ?? '') !== '' ? '-' . $m[3] : '';
            $mapped = [];
            foreach ($sides as $side) {
                $mapped['border-' . $side . $suffix] = $value;
            }

            return $mapped;
        }

        return null;
    }

    /** @return list<string> */
    private static function split(string $value): array
    {
        return preg_split('/\s+(?![^(]*\))/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
