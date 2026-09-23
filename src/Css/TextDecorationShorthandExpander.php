<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Css\Color\ColorParser;

/**
 * Expands the `text-decoration` shorthand into `text-decoration-line`, so that an author's
 * shorthand actually overrides a longhand set by the UA sheet.
 *
 * Without the expansion the two stayed in separate slots of the computed style and the paint
 * layer read `text-decoration-line ?? text-decoration` — the longhand simply won, whoever set it.
 * That made `<a style="text-decoration: none">` powerless against the UA sheet's underline for
 * links (8 occurrences in 3 corpus documents), and it would do the same to any element whose
 * decoration the UA defines, `<u>` and `<s>` included.
 *
 * The line keywords, the style and the colour are carried over; a thickness is dropped. A
 * declaration with no line keyword at all is left unexpanded so it keeps whatever handling it had.
 */
final class TextDecorationShorthandExpander
{
    private const LINE_KEYWORDS = ['none', 'underline', 'overline', 'line-through', 'blink'];

    /** @return array<string,string>|null */
    public function expand(string $property, string $value): ?array
    {
        if ($property !== 'text-decoration') {
            return null;
        }

        $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: [];
        $lines = array_values(array_intersect($tokens, self::LINE_KEYWORDS));
        if ($lines === []) {
            return null;
        }

        // `none` in the list clears everything, whatever else came with it.
        $line = in_array('none', $lines, true) ? 'none' : implode(' ', array_unique($lines));

        // The shorthand also sets the style and the colour, back to their initial values when it
        // does not name them (CSS Text Decoration 3 §2.4).
        $expanded = ['text-decoration-line' => $line, 'text-decoration-style' => 'solid', 'text-decoration-color' => 'currentcolor'];
        foreach (preg_split('/\s+(?![^(]*\))/', trim($value)) ?: [] as $token) {
            $lower = strtolower($token);
            if (in_array($lower, self::LINE_KEYWORDS, true)) continue;
            if (in_array($lower, ['solid', 'double', 'dotted', 'dashed', 'wavy'], true)) {
                $expanded['text-decoration-style'] = $lower;
            } elseif ($lower === 'currentcolor' || ColorParser::parse($token) !== null) {
                $expanded['text-decoration-color'] = $token;
            }
        }

        return $expanded;
    }
}
