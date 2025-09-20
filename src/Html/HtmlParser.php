<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Html;

final class HtmlParser
{
    private int $idSeq = 0;

    public function parse(string $html): HtmlDocument
    {
        $this->idSeq = 0;
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $options = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $options |= LIBXML_HTML_NOIMPLIED;
        }
        if (defined('LIBXML_HTML_NODEFDTD')) {
            $options |= LIBXML_HTML_NODEFDTD;
        }

        $dom->loadHTML($html, $options);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $roots = [];
        $container = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        if ($container !== null) {
            foreach ($container->childNodes as $child) {
                $converted = $this->convertNode($child, []);
                if ($converted !== null) {
                    $roots[] = $converted;
                }
            }
        }

        return new HtmlDocument($roots);
    }

    /**
     * @param array<int, array<string, mixed>> $ancestors
     * @return array<string, mixed>|null
     */
    private function convertNode(\DOMNode $node, array $ancestors): ?array
    {
        if ($node instanceof \DOMText) {
            $text = $node->nodeValue ?? '';
            if (trim($text) === '') {
                return null;
            }
            return [
                'nodeId' => $this->nextId(),
                'type' => 'text',
                'text' => preg_replace('/\s+/', ' ', $text) ?? '',
                'children' => [],
                'ancestors' => $ancestors,
            ];
        }

        if (!($node instanceof \DOMElement)) {
            return null;
        }

        $attributes = [];
        foreach ($node->attributes ?? [] as $attr) {
            if ($attr instanceof \DOMAttr) {
                $attributes[strtolower($attr->nodeName)] = $attr->nodeValue ?? '';
            }
        }

        $element = [
            'nodeId' => $this->nextId(),
            'type' => 'element',
            'tag' => strtolower($node->tagName),
            'attributes' => $attributes,
            'children' => [],
            'ancestors' => $ancestors,
        ];

        $childAncestors = $ancestors;
        $childAncestors[] = [
            'nodeId' => $element['nodeId'],
            'type' => 'element',
            'tag' => $element['tag'],
            'attributes' => $attributes,
        ];

        foreach ($node->childNodes as $childNode) {
            $convertedChild = $this->convertNode($childNode, $childAncestors);
            if ($convertedChild !== null) {
                $element['children'][] = $convertedChild;
            }
        }

        return $element;
    }

    private function nextId(): string
    {
        $this->idSeq++;
        return 'n' . $this->idSeq;
    }
}
