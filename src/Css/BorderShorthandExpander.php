<?php

declare(strict_types=1);

namespace Pagyra\Css;

final class BorderShorthandExpander
{
    private const SIDES = ['top', 'right', 'bottom', 'left'];
    private const STYLES = [
        'none', 'hidden', 'solid', 'dashed', 'dotted',
        'double', 'groove', 'ridge', 'inset', 'outset',
    ];
    private const WIDTH_KEYWORDS = [
        'thin' => '1px',
        'medium' => '3px',
        'thick' => '5px',
    ];

    /** @return array<string,string>|null */
    public function expand(string $property, string $value): ?array
    {
        $property = strtolower(trim($property));
        return match ($property) {
            'border' => $this->expandBorder($value, self::SIDES),
            'border-top', 'border-right', 'border-bottom', 'border-left'
                => $this->expandBorder($value, [substr($property, strlen('border-'))]),
            'border-width' => $this->expandBoxValues('width', $value, true),
            'border-style' => $this->expandBoxValues('style', $value, false),
            'border-color' => $this->expandBoxValues('color', $value, false),
            default => null,
        };
    }

    /** @param list<string> $sides @return array<string,string> */
    private function expandBorder(string $value, array $sides): array
    {
        $parts = $this->splitTopLevelWhitespace($value);
        if ($parts === []) return [];

        $width = null;
        $style = null;
        $color = null;

        foreach ($parts as $part) {
            if ($width === null) {
                $parsedWidth = $this->parseWidth($part);
                if ($parsedWidth !== null) {
                    $width = $parsedWidth;
                    continue;
                }
            }

            $lower = strtolower($part);
            if ($style === null && in_array($lower, self::STYLES, true)) {
                $style = $lower;
                continue;
            }

            if ($color === null) $color = $part;
        }

        if (in_array($style, ['none', 'hidden'], true)) {
            $width = '0px';
        } elseif ($width === null && $style !== null) {
            $width = '3px';
        }

        $result = [];
        foreach ($sides as $side) {
            if ($width !== null) $result['border-' . $side . '-width'] = $width;
            if ($style !== null) $result['border-' . $side . '-style'] = $style;
            if ($color !== null) $result['border-' . $side . '-color'] = $color;
        }
        return $result;
    }

    /** @return array<string,string> */
    private function expandBoxValues(string $suffix, string $value, bool $widths): array
    {
        $parts = $this->splitTopLevelWhitespace($value);
        if ($parts === [] || count($parts) > 4) return [];
        if ($widths) {
            foreach ($parts as $index => $part) {
                $parsed = $this->parseWidth($part);
                if ($parsed === null) return [];
                $parts[$index] = $parsed;
            }
        }
        $expanded = $this->expandFour($parts);
        $result = [];
        foreach (self::SIDES as $index => $side) {
            $result['border-' . $side . '-' . $suffix] = $expanded[$index];
        }
        return $result;
    }

    private function parseWidth(string $value): ?string
    {
        $trimmed = trim($value);
        $lower = strtolower($trimmed);
        if (isset(self::WIDTH_KEYWORDS[$lower])) return self::WIDTH_KEYWORDS[$lower];
        if ($lower === '0') return '0px';
        if (preg_match('/^(?:\d*\.)?\d+(?:px|pt|pc|in|cm|mm|q|em|rem|ex|ch|vw|vh|vmin|vmax|%)$/i', $trimmed) === 1) {
            return $trimmed;
        }
        if (preg_match('/^(?:calc|min|max|clamp)\(/i', $trimmed) === 1) return $trimmed;
        return null;
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
