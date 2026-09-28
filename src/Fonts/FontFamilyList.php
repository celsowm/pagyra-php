<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

/**
 * Splits a CSS `font-family` value into its family names.
 *
 * A comma separates families only outside quotes. `font-family: "Calibri, sans-serif"` names one
 * family, literally called `Calibri, sans-serif`, and not Calibri with a sans-serif fallback:
 * browsers look it up as a single name, find nothing, and go on to the next entry or to the
 * default font. Word-processor templates (CKEditor, in the JFRJ and TRF2 documents) write it
 * that way, and wkhtmltopdf draws that text in the default serif face.
 */
final class FontFamilyList
{
    /**
     * @return list<string> family names in order, without their quotes, empty entries dropped
     */
    public static function names(?string $value): array
    {
        if ($value === null || trim($value) === '') return [];

        $names = [];
        $current = '';
        $quote = null;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($quote !== null) {
                if ($char === $quote) $quote = null;
                $current .= $char;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($char === ',') {
                $names[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $names[] = $current;

        $result = [];
        foreach ($names as $name) {
            $name = trim($name);
            if (strlen($name) >= 2 && ($name[0] === '"' || $name[0] === "'") && $name[-1] === $name[0]) {
                $name = trim(substr($name, 1, -1));
            }
            if ($name !== '') $result[] = $name;
        }
        return $result;
    }
}
