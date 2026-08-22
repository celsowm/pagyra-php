<?php

declare(strict_types=1);

namespace Pagyra\Style;

use Pagyra\Css\DeclarationParser;
use Pagyra\Css\SelectorMatcher;
use Pagyra\Css\StyleRule;
use Pagyra\Dom\Node;

final class StyleComputer
{
    private const INHERITED = [
        'color', 'font-family', 'font-size', 'font-style', 'font-weight',
        'line-height', 'text-align', 'visibility', 'white-space',
    ];

    public function __construct(
        private readonly SelectorMatcher $selectorMatcher = new SelectorMatcher(),
        private readonly DeclarationParser $declarationParser = new DeclarationParser(),
    ) {
    }

    /** @param list<StyleRule> $rules */
    public function computeTree(Node $root, array $rules): StyledNode
    {
        return $this->computeNode($root, $rules, null);
    }

    /** @param list<StyleRule> $rules */
    private function computeNode(Node $node, array $rules, ?ComputedStyle $parent): StyledNode
    {
        $properties = [];
        if ($parent !== null) {
            foreach (self::INHERITED as $property) {
                $value = $parent->get($property);
                if ($value !== null) {
                    $properties[$property] = $value;
                }
            }
        }

        $winners = [];
        if ($node->type === 'element') {
            foreach ($rules as $rule) {
                if (!$this->selectorMatcher->matches($node, $rule->selector)) {
                    continue;
                }
                foreach ($rule->declarations as $property => $value) {
                    $current = $winners[$property] ?? null;
                    $weight = [$rule->specificity, $rule->sourceOrder];
                    if ($current === null || $weight >= $current['weight']) {
                        $winners[$property] = ['weight' => $weight, 'value' => $value];
                    }
                }
            }

            foreach ($winners as $property => $winner) {
                $properties[$property] = $winner['value'];
            }

            if ($node->inlineStyle() !== null) {
                foreach ($this->declarationParser->parse($node->inlineStyle()) as $property => $value) {
                    $properties[$property] = $value;
                }
            }
        }

        ksort($properties);
        $style = new ComputedStyle($properties);
        $children = [];
        foreach ($node->children as $child) {
            $children[] = $this->computeNode($child, $rules, $style);
        }

        return new StyledNode($node, $style, $children);
    }
}
