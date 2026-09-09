<?php

declare(strict_types=1);

namespace Pagyra\Style;

use Pagyra\Dom\Node;

final class UserAgentStyles
{
    /** @return array<string,string> */
    public function forNode(Node $node): array
    {
        if ($node->type !== 'element') {
            return [];
        }

        return match ($node->tagName) {
            'html', 'body', 'div', 'p', 'section', 'article', 'header', 'footer', 'main', 'nav',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
            // The reference's element table does not list these, but its global default display
            // is block (LayoutDefaults.getDisplay()), so that is what they resolve to there. Here
            // an unlisted element falls back to inline instead, which is not merely a styling
            // difference: an inline element sitting among block siblings is dropped by the block
            // engine, so a document built out of <blockquote> loses that content entirely.
            'blockquote', 'figure', 'figcaption', 'pre', 'address', 'dl', 'dt', 'dd',
            'fieldset', 'form', 'aside', 'hgroup', 'center', 'details', 'summary',
            'dir', 'menu', 'legend' => ['display' => 'block'],
            'table' => ['display' => 'table'],
            // The table-internal displays, which the reference spells out the same way
            // (pagyra-js `src/css/ua-defaults/element-defaults.ts`). `tr` used to resolve to
            // `block` here and the group wrappers to `inline`, which forced the table engine to
            // find its rows by tag name instead of by computed display.
            'tr' => ['display' => 'table-row'],
            'thead' => ['display' => 'table-header-group'],
            'tbody' => ['display' => 'table-row-group'],
            'tfoot' => ['display' => 'table-footer-group'],
            'caption' => ['display' => 'table-caption'],
            'colgroup' => ['display' => 'table-column-group'],
            'col' => ['display' => 'table-column'],
            'td', 'th' => ['display' => 'table-cell'],
            'span', 'a', 'strong', 'b', 'em', 'i', 'small', 'label',
            'u', 's', 'del', 'strike', 'code', 'sup', 'sub' => ['display' => 'inline'],
            'hr' => ['display' => 'block'],
            'img', 'svg', 'input', 'button', 'select', 'textarea' => ['display' => 'inline-block'],
            default => [],
        } + match ($node->tagName) {
            'body' => ['margin-top' => '8px', 'margin-right' => '8px', 'margin-bottom' => '8px', 'margin-left' => '8px'],
            'p' => ['margin-top' => '1em', 'margin-bottom' => '1em'],
            'h1' => ['font-size' => '2em', 'font-weight' => 'bold', 'margin-top' => '0.67em', 'margin-bottom' => '0.67em'],
            'h2' => ['font-size' => '1.5em', 'font-weight' => 'bold', 'margin-top' => '0.83em', 'margin-bottom' => '0.83em'],
            'h3' => ['font-size' => '1.17em', 'font-weight' => 'bold', 'margin-top' => '1em', 'margin-bottom' => '1em'],
            'h4' => ['font-size' => '1em', 'font-weight' => 'bold', 'margin-top' => '1.33em', 'margin-bottom' => '1.33em'],
            'h5' => ['font-size' => '0.83em', 'font-weight' => 'bold', 'margin-top' => '1.67em', 'margin-bottom' => '1.67em'],
            'h6' => ['font-size' => '0.67em', 'font-weight' => 'bold', 'margin-top' => '2.33em', 'margin-bottom' => '2.33em'],
            'strong', 'b' => ['font-weight' => 'bold'],
            'em', 'i' => ['font-style' => 'italic'],
            'u' => ['text-decoration-line' => 'underline'],
            's', 'del', 'strike' => ['text-decoration-line' => 'line-through'],
            'code' => ['font-family' => "Monaco, 'Courier New', monospace"],
            // The reference gives <a> only the colour, with no decoration at all
            // (pagyra-js `src/css/ua-defaults/element-defaults.ts`), so this is a deliberate
            // departure from it towards CSS 2.1 and WebKit: 221 corpus documents carry 656 links
            // whose own CSS says nothing about decoration, and every one of them is underlined in
            // the wkhtmltopdf output this port is compared against.
            'a' => ['color' => '#0000EE', 'text-decoration-line' => 'underline'],
            // CSS 2.1's default stylesheet, absent from the reference's element table. Without
            // them `art. 1<sup>o</sup>` came out as "art. 1o", at body size and on the body
            // baseline: 37 corpus documents use <sup> (50 of them for exactly that ordinal) and
            // 6 use <sub>. `smaller` is resolved by FontSizeKeywords.
            'sup' => ['vertical-align' => 'super', 'font-size' => 'smaller'],
            'sub' => ['vertical-align' => 'sub', 'font-size' => 'smaller'],
            // The reference models the rule as `borderTop: 1` plus `borderColor`, with no
            // border-style field at all. In this port a side with no explicit style resolves to
            // `none` and collapses to zero width, so the equivalent visible line needs the style
            // spelled out; without it an <hr> is laid out but paints nothing.
            'hr' => [
                'margin-top' => '0.5em',
                'margin-bottom' => '0.5em',
                'border-top-width' => '1px',
                'border-top-style' => 'solid',
                'border-top-color' => '#a0a0a0',
            ],
            'th' => [
                'font-weight' => 'bold',
                'text-align' => 'center',
                'vertical-align' => 'middle',
                'padding-top' => '8px', 'padding-right' => '8px', 'padding-bottom' => '8px', 'padding-left' => '8px',
            ],
            'td' => [
                'vertical-align' => 'middle',
                'padding-top' => '8px', 'padding-right' => '8px', 'padding-bottom' => '8px', 'padding-left' => '8px',
            ],
            'ul', 'ol' => ['margin-top' => '1em', 'margin-bottom' => '1em', 'padding-left' => '40px'],
            // CSS 2.1 default stylesheet values, applied only where their absence is visibly
            // wrong (an unindented blockquote reads as an ordinary paragraph). The reference
            // defines no styling for these at all, so this is the standards fallback.
            'blockquote', 'figure' => [
                'margin-top' => '1em', 'margin-bottom' => '1em',
                'margin-left' => '40px', 'margin-right' => '40px',
            ],
            'pre' => ['margin-top' => '1em', 'margin-bottom' => '1em', 'white-space' => 'pre', 'font-family' => "Monaco, 'Courier New', monospace"],
            'dl' => ['margin-top' => '1em', 'margin-bottom' => '1em'],
            'dd' => ['margin-left' => '40px'],
            'address' => ['font-style' => 'italic'],
            'center' => ['text-align' => 'center'],
            default => [],
        };
    }
}
