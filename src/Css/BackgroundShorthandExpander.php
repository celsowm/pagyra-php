<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Css\Color\ColorParser;

/**
 * Expands `background: <value>` into `background-color` when the shorthand carries a color
 * token, following the same "detect one component, expand the rest" approach as
 * BorderShorthandExpander. Scoped to color only: DisplayListBuilder/PdfSerializer only paint
 * `background-color` today, there is no background-image/gradient support anywhere in the
 * paint pipeline, so expanding the other shorthand components (image, position, repeat,
 * attachment, size, origin, clip) would have nothing to consume them. pagyra-js's own
 * background-parser.ts is a full layered background system (gradients, multiple images,
 * position/size/repeat/origin) well beyond what this port implements; porting only the color
 * detection here (does this token parse as a color?) mirrors its isColorValue() check without
 * porting the layered model it feeds into.
 */
final class BackgroundShorthandExpander
{
    /** @return array<string,string>|null */
    public function expand(string $property, string $value): ?array
    {
        if (strtolower(trim($property)) !== 'background') {
            return null;
        }

        foreach ($this->splitTopLevelWhitespace($value) as $token) {
            if (ColorParser::parse($token) !== null) {
                return ['background-color' => $token];
            }
        }

        return null;
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
