<?php

declare(strict_types=1);

namespace Pagyra\Css;

/**
 * Expands `margin`/`padding` into their four `-top`/`-right`/`-bottom`/`-left` longhands at
 * cascade time, the same 1/2/3/4-value box shorthand rule BorderShorthandExpander already
 * applies for border-width/style/color, and the same shape pagyra-js's own margin-parser.ts /
 * padding-parser.ts use (applyBoxShorthand expanding directly into the four longhand fields).
 *
 * Without this, `margin`/`padding` were stored verbatim under their own shorthand key and never
 * actually reached the elements that have a UA-stylesheet longhand default (p/h1/h2/h3's
 * margin-top/margin-bottom, ul/ol's margin-top/margin-bottom/padding-left):
 * StyleComputer::computeNode() seeds $properties with those UA longhand values before the
 * cascade runs, a later `margin: 0` rule or inline style only ever overwrote the separate
 * `margin` key, and ComputedStyle::get('margin-top', ...) finds the UA value already sitting
 * under 'margin-top' and never falls through to the shorthand's derived default. The effect
 * was that `<p style="margin:0">`, `<h1 style="margin:...">`, and `<ul style="padding:0">` (all
 * extremely common patterns) silently kept the UA default spacing regardless of what the
 * shorthand said, while the four longhand properties themselves (margin-top: 0, etc.) worked
 * correctly the whole time, since those are plain unprefixed keys with no separate default.
 */
final class BoxEdgeShorthandExpander
{
    private const SIDES = ['top', 'right', 'bottom', 'left'];

    /** @return array<string,string>|null */
    public function expand(string $property, string $value): ?array
    {
        $property = strtolower(trim($property));
        return match ($property) {
            'margin', 'padding' => $this->expandBox($property, $value),
            default => null,
        };
    }

    /** @return array<string,string> */
    private function expandBox(string $prefix, string $value): array
    {
        $parts = $this->splitTopLevelWhitespace($value);
        if ($parts === [] || count($parts) > 4) return [];

        $expanded = $this->expandFour($parts);
        $result = [];
        foreach (self::SIDES as $index => $side) {
            $result[$prefix . '-' . $side] = $expanded[$index];
        }
        return $result;
    }

    /** @param list<string> $parts @return list<string> */
    private function expandFour(array $parts): array
    {
        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0], $parts[1], $parts[2], $parts[3]],
        };
    }

    /** @return list<string> */
    private function splitTopLevelWhitespace(string $value): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $escaped = false;

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $ch = $value[$i];
            if ($escaped) {
                $buffer .= $ch;
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $buffer .= $ch;
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                $buffer .= $ch;
                if ($ch === $quote) $quote = null;
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $buffer .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                $buffer .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth = max(0, $depth - 1);
                $buffer .= $ch;
                continue;
            }
            if (ctype_space($ch) && $depth === 0) {
                if ($buffer !== '') {
                    $parts[] = trim($buffer);
                    $buffer = '';
                }
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') $parts[] = trim($buffer);
        return $parts;
    }
}
