<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Css\Color\ColorParser;

/**
 * Expands the `background` shorthand (CSS Backgrounds 3 §3.10) into `background-color`,
 * `background-image`, `background-repeat`, `background-position` and `background-size`, with
 * every component the shorthand leaves out reset to its initial value, as the spec says:
 * `background: url(a.png) no-repeat` clears an earlier background colour, and `background: none`
 * clears everything. Attachment, origin and clip keywords are recognised and dropped, since the
 * paint layer always uses the padding box to position and the border box to paint. Only the
 * last layer of a comma-separated list is read, which is the one that carries the colour.
 *
 * It used to extract the colour alone, so an image or a gradient in the shorthand never reached
 * the paint layer, and a shorthand without a colour left the previous colour in place.
 */
final class BackgroundShorthandExpander
{
    private const REPEAT = ['repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'space', 'round'];
    private const POSITION = ['left', 'right', 'top', 'bottom', 'center'];
    private const IGNORED = ['scroll', 'fixed', 'local', 'border-box', 'padding-box', 'content-box', 'text'];

    /** @return array<string,string>|null */
    public function expand(string $property, string $value): ?array
    {
        if (strtolower(trim($property)) !== 'background') {
            return null;
        }
        $layers = $this->splitTopLevel($value, ',');
        $tokens = $this->splitTopLevelWhitespace($layers === [] ? $value : $layers[count($layers) - 1]);

        $expanded = [
            'background-color' => 'transparent',
            'background-image' => 'none',
            'background-repeat' => 'repeat',
            'background-position' => '0% 0%',
            'background-size' => 'auto',
        ];
        $position = [];
        $repeat = [];
        $size = null;
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $lower = strtolower($token);
            if ($token === '/') {
                $size = [];
                continue;
            }
            if (str_starts_with($token, '/')) {
                $size = [substr($token, 1)];
                continue;
            }
            if (is_array($size) && count($size) < 2 && ($lower === 'cover' || $lower === 'contain' || $lower === 'auto' || preg_match('/^-?\d*\.?\d+[a-z%]*$/', $lower) === 1)) {
                $size[] = $token;
                continue;
            }
            $size = is_array($size) && $size !== [] ? $size : ($size === [] ? null : $size);
            if ($lower === 'none' || str_starts_with($lower, 'url(') || preg_match('/^(repeating-)?(linear|radial|conic)-gradient\(/', $lower) === 1) {
                $expanded['background-image'] = $token;
            } elseif (in_array($lower, self::REPEAT, true)) {
                $repeat[] = $lower;
            } elseif (in_array($lower, self::IGNORED, true)) {
                continue;
            } elseif (in_array($lower, self::POSITION, true) || preg_match('/^-?\d*\.?\d+[a-z%]*$/', $lower) === 1) {
                if (str_contains($token, '/')) {
                    [$pos, $sz] = explode('/', $token, 2);
                    if ($pos !== '') $position[] = $pos;
                    $size = $sz !== '' ? [$sz] : [];
                    continue;
                }
                $position[] = $token;
            } elseif (ColorParser::parse($token) !== null || $lower === 'transparent' || $lower === 'currentcolor') {
                $expanded['background-color'] = $token;
            } elseif (str_contains($token, '/')) {
                [$pos, $sz] = explode('/', $token, 2);
                if ($pos !== '') $position[] = $pos;
                $size = $sz !== '' ? [$sz] : [];
            }
        }
        if ($position !== []) $expanded['background-position'] = implode(' ', $position);
        if ($repeat !== []) $expanded['background-repeat'] = implode(' ', $repeat);
        if (is_array($size) && $size !== []) $expanded['background-size'] = implode(' ', $size);

        return $expanded;
    }

    /** @return list<string> */
    private function splitTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        foreach (str_split($value) as $ch) {
            if ($ch === '(') $depth++;
            if ($ch === ')') $depth = max(0, $depth - 1);
            if ($depth === 0 && $ch === $separator) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        $parts[] = trim($buffer);

        return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
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
