<?php

declare(strict_types=1);

namespace Pagyra\Html;

use Pagyra\Dom\Node;
use Pagyra\Image\ImageSourceIntrinsicSizeResolver;

final class HtmlParser
{
    private const SKIPPED_CONTENT_TAGS = ['head', 'meta', 'title', 'link', 'script'];

    private readonly ImageSourceIntrinsicSizeResolver $imageIntrinsicSizeResolver;

    public function __construct(?ImageSourceIntrinsicSizeResolver $imageIntrinsicSizeResolver = null)
    {
        $this->imageIntrinsicSizeResolver = $imageIntrinsicSizeResolver ?? new ImageSourceIntrinsicSizeResolver();
    }

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

        $intrinsicWidth = null;
        $intrinsicHeight = null;

        if ($tagName === 'img') {
            $size = $this->imageIntrinsicSizeResolver->resolve($attributes['src'] ?? null);
            $intrinsicWidth = $size?->width;
            $intrinsicHeight = $size?->height;
        } elseif ($tagName === 'svg') {
            [$intrinsicWidth, $intrinsicHeight] = $this->resolveSvgIntrinsicSize($attributes);
        }

        return Node::element(
            $tagName,
            $attributes,
            $children,
            $intrinsicWidth,
            $intrinsicHeight,
        );
    }

    /** @param array<string,string> $attributes @return array{0:float,1:float} */
    private function resolveSvgIntrinsicSize(array $attributes): array
    {
        $width = $this->positiveSvgNumber($attributes['width'] ?? null);
        $height = $this->positiveSvgNumber($attributes['height'] ?? null);

        $viewBox = $this->parseViewBox($attributes['viewbox'] ?? null);
        if ($viewBox !== null) {
            if ($width === null) {
                $width = $viewBox['width'];
            }
            if ($height === null) {
                $height = $viewBox['height'];
            }
        }

        $width ??= 100.0;
        $height ??= $width;

        return [$width > 0.0 ? $width : 100.0, $height > 0.0 ? $height : 100.0];
    }

    private function positiveSvgNumber(?string $raw): ?float
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (preg_match('/^[\s]*([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $raw, $matches) !== 1) {
            return null;
        }

        $value = (float) $matches[1];
        return is_finite($value) && $value > 0.0 ? $value : null;
    }

    /** @return array{minX:float,minY:float,width:float,height:float}|null */
    private function parseViewBox(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
        if (count($parts) !== 4) {
            return null;
        }
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
        }

        [$minX, $minY, $width, $height] = array_map('floatval', $parts);
        if (!is_finite($width) || !is_finite($height) || $width <= 0.0 || $height <= 0.0) {
            return null;
        }

        return [
            'minX' => $minX,
            'minY' => $minY,
            'width' => $width,
            'height' => $height,
        ];
    }
}
