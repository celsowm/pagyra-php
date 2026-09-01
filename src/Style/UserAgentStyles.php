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
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'tr' => ['display' => 'block'],
            'table' => ['display' => 'table'],
            'td', 'th' => ['display' => 'table-cell'],
            'span', 'a', 'strong', 'b', 'em', 'i', 'small', 'label',
            'u', 's', 'del', 'strike', 'code' => ['display' => 'inline'],
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
            'a' => ['color' => '#0000EE'],
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
            default => [],
        };
    }
}
