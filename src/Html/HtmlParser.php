<?php

declare(strict_types=1);

namespace Pagyra\Html;

use Pagyra\Dom\Node;

final class HtmlParser
{
    private const SKIPPED_CONTENT_TAGS = ['head', 'meta', 'title', 'link', 'script'];

    public function parse(string $html): Node
    {
        return $this->parseDocument($html)->root;
    }

    public function parseDocument(string $html): HtmlDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $normalized = $this->normalizeHtmlInput($html);
            $document->loadHTML('<?xml encoding="UTF-8">' . $normalized, LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $embeddedCss = [];
        foreach ($document->getElementsByTagName('style') as $style) {
            $embeddedCss[] = $style->textContent ?? '';
        }

        $stylesheetHrefs = [];
        foreach ($document->getElementsByTagName('link') as $link) {
            if (strtolower($link->getAttribute('rel')) === 'stylesheet') {
                $href = trim($link->getAttribute('href'));
                if ($href !== '') {
                    $stylesheetHrefs[] = $href;
                }
            }
        }

        $contentRoot = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        if ($contentRoot === null) {
            return new HtmlDocument(Node::document([]), $embeddedCss, $stylesheetHrefs);
        }

        $children = [];
        foreach ($contentRoot->childNodes as $child) {
            $node = $this->convert($child);
            if ($node !== null) {
                $children[] = $node;
            }
        }

        return new HtmlDocument(Node::document($children), $embeddedCss, $stylesheetHrefs);
    }

    public function normalizeHtmlInput(string $html): string
    {
        if (preg_match('/<\s*html[\s>]/i', $html) === 1) {
            return $html;
        }

        return '<!doctype html><html><head></head><body>' . $html . '</body></html>';
    }

    private function convert(\DOMNode $node): ?Node
    {
        if ($node instanceof \DOMText) {
            return Node::text($node->data);
        }

        if (!$node instanceof \DOMElement) {
            return null;
        }

        $tagName = strtolower($node->tagName);
        if (in_array($tagName, self::SKIPPED_CONTENT_TAGS, true)) {
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

        return Node::element($tagName, $attributes, $children);
    }
}
