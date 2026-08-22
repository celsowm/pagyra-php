<?php

declare(strict_types=1);

namespace Pagyra\Html;

use Pagyra\Dom\Node;

final class HtmlParser
{
    public function parse(string $html): Node
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $wrapped = '<!DOCTYPE html><html><body><pagyra-root>' . $html . '</pagyra-root></body></html>';
            $document->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $roots = $document->getElementsByTagName('pagyra-root');
        if ($roots->length === 0) {
            return Node::document([]);
        }

        $children = [];
        foreach ($roots->item(0)->childNodes as $child) {
            $node = $this->convert($child);
            if ($node !== null) {
                $children[] = $node;
            }
        }

        return Node::document($children);
    }

    private function convert(\DOMNode $node): ?Node
    {
        if ($node instanceof \DOMText) {
            return Node::text($node->data);
        }

        if (!$node instanceof \DOMElement) {
            return null;
        }

        $attributes = [];
        foreach ($node->attributes as $attribute) {
            $attributes[strtolower($attribute->name)] = $attribute->value;
        }
        ksort($attributes);

        $children = [];
        foreach ($node->childNodes as $child) {
            $converted = $this->convert($child);
            if ($converted !== null) {
                $children[] = $converted;
            }
        }

        return Node::element($node->tagName, $attributes, $children);
    }
}
