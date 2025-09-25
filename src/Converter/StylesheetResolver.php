<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter;

use Celsowm\PagyraPhp\Html\HtmlDocument;

final class StylesheetResolver
{
    public function resolve(HtmlDocument $document, ?string $css = null): string
    {
        $stylesheet = $css ?? $this->extractEmbeddedCss($document);

        return $this->mergeWithDefaultStylesheet($stylesheet);
    }

    private function mergeWithDefaultStylesheet(string $stylesheet): string
    {
        $defaults = $this->getDefaultStylesheet();
        $stylesheet = trim($stylesheet);
        if ($defaults === '') {
            return $stylesheet;
        }
        if ($stylesheet === '') {
            return $defaults;
        }

        return $defaults . "\n" . $stylesheet;
    }

    private function getDefaultStylesheet(): string
    {
    return <<<'CSS'
/* inline */
strong, b { font-weight: bold; }
em, i { font-style: italic; }

/* headings (equivalentes às defaults dos browsers) */
h1 { font-size: 24pt; font-weight: bold;}
h2 { font-size: 18pt; }
h3 { font-size: 14pt; }
h4 { font-size: 12pt; }
h5 { font-size: 10pt; }
h6 { font-size: 9pt; }

CSS;
    }

    private function extractEmbeddedCss(HtmlDocument $document): string
    {
        $stylesheets = $document->getEmbeddedStylesheets();
        if ($stylesheets !== []) {
            return trim(implode("\n", $stylesheets));
        }

        $css = [];
        $document->eachElement(function (array $node) use (&$css): void {
            if (strtolower((string)($node['tag'] ?? '')) !== 'style') {
                return;
            }
            $css[] = $this->collectText($node);
        });

        return trim(implode("\n", array_filter($css)));
    }

    private function collectText(array $node): string
    {
        $buffer = '';
        foreach ($node['children'] ?? [] as $child) {
            $type = $child['type'] ?? '';
            if ($type === 'text') {
                $buffer .= $child['text'] ?? '';
            } elseif ($type === 'element') {
                $buffer .= $this->collectText($child);
            }
        }

        return trim($buffer);
    }
}
