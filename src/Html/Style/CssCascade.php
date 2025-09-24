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

        foreach ($document->getRoots() as $rootNode) {
            $this->computeForNodeAndChildren($rootNode, null, $rules, $computed);
        }

        return $computed;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $rules
     * @param array<string, ComputedStyle> $computed
     */
    private function computeForNodeAndChildren(array $node, ?ComputedStyle $parentStyle, array $rules, array &$computed): void
    {
        $type = $node['type'] ?? null;
        if ($type !== 'element') {
            return;
        }
        $nodeId = (string)($node['nodeId'] ?? '');
        if ($nodeId === '') {
            return;
        }

        $style = new ComputedStyle();

        // 1. Inheritance
        if ($parentStyle !== null) {
            $style->inheritFrom($parentStyle);
        }

        // 2. Apply rules from stylesheets
        foreach ($rules as $rule) {
            if ($this->matcher->matches($node, $rule['selector'])) {
                $specificity = $this->matcher->calculateSpecificity($rule['selector']);
                $style->applyDeclarations($rule['declarations'], $specificity, $rule['order']);
            }
        }

        // 3. Apply inline styles (highest specificity)
        $inlineDeclarations = $this->parseInlineStyle($node['attributes']['style'] ?? null);
        if ($inlineDeclarations !== []) {
            $style->applyDeclarations($inlineDeclarations, [1, 0, 0, 0], PHP_INT_MAX); // Inline style specificity
        }

        $computed[$nodeId] = $style;

        // Recurse for children
        foreach ($node['children'] ?? [] as $childNode) {
            $this->computeForNodeAndChildren($childNode, $style, $rules, $computed);
        }
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