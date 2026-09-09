<?php

declare(strict_types=1);

namespace Pagyra\Css;

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
 * Only the line keywords are carried over; a colour, style or thickness in the shorthand is
 * dropped, which is what the paint layer already does with them. A declaration with no line
 * keyword at all is left unexpanded so it keeps whatever handling it had.
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

        return ['text-decoration-line' => $line];
    }
}
