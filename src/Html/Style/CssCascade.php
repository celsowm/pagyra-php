<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Html\Style;

use Celsowm\PagyraPhp\Css\CssOM;
use Celsowm\PagyraPhp\Css\SelectorMatcher;
use Celsowm\PagyraPhp\Html\HtmlDocument;

final class CssCascade
{
    private SelectorMatcher $matcher;

    public function __construct(?SelectorMatcher $matcher = null)
    {
        $this->matcher = $matcher ?? new SelectorMatcher();
    }

    /**
     * @return array<string, ComputedStyle>
     */
    public function compute(HtmlDocument $document, CssOM $cssOM): array
    {
        $computed = [];
        $rules = $cssOM->getRules();

        $document->eachElement(function (array $node) use (&$computed, $rules): void {
            $nodeId = (string)($node['nodeId'] ?? '');
            if ($nodeId === '') {
                return;
            }

            $style = new ComputedStyle();
            foreach ($rules as $rule) {
                if ($this->matcher->matches($node, $rule['selector'])) {
                    $specificity = $this->matcher->calculateSpecificity($rule['selector']);
                    $style->applyDeclarations($rule['declarations'], $specificity, $rule['order']);
                }
            }

            $inlineDeclarations = $this->parseInlineStyle($node['attributes']['style'] ?? null);
            if ($inlineDeclarations !== []) {
                $style->applyDeclarations($inlineDeclarations, [1, 0, 0], PHP_INT_MAX);
            }

            $computed[$nodeId] = $style;
        });

        return $computed;
    }

    /**
     * @return array<string, string>
     */
    private function parseInlineStyle(?string $style): array
    {
        if ($style === null) {
            return [];
        }

        $declarations = [];
        foreach (explode(';', $style) as $declaration) {
            if (strpos($declaration, ':') === false) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $declaration, 2));
            if ($name === '' || $value === '') {
                continue;
            }
            $declarations[strtolower($name)] = $value;
        }

        return $declarations;
    }
}
