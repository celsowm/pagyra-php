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
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'tr' => ['display' => 'block'],
            'span', 'a', 'strong', 'b', 'em', 'i', 'small', 'label' => ['display' => 'inline'],
            'img', 'svg', 'input', 'button', 'select', 'textarea' => ['display' => 'inline-block'],
            default => [],
        } + match ($node->tagName) {
            'body' => ['margin-top' => '8px', 'margin-right' => '8px', 'margin-bottom' => '8px', 'margin-left' => '8px'],
            'p' => ['margin-top' => '1em', 'margin-bottom' => '1em'],
            'h1' => ['font-size' => '2em', 'font-weight' => 'bold', 'margin-top' => '0.67em', 'margin-bottom' => '0.67em'],
            'h2' => ['font-size' => '1.5em', 'font-weight' => 'bold', 'margin-top' => '0.83em', 'margin-bottom' => '0.83em'],
            'h3' => ['font-size' => '1.17em', 'font-weight' => 'bold', 'margin-top' => '1em', 'margin-bottom' => '1em'],
            'strong', 'b' => ['font-weight' => 'bold'],
            'em', 'i' => ['font-style' => 'italic'],
            'ul', 'ol' => ['margin-top' => '1em', 'margin-bottom' => '1em', 'padding-left' => '40px'],
            default => [],
        };
    }
}
