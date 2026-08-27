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
        'text-decoration', 'text-decoration-line',
    ];

    public function __construct(
        private readonly SelectorMatcher $selectorMatcher = new SelectorMatcher(),
        private readonly DeclarationParser $declarationParser = new DeclarationParser(),
        private readonly UserAgentStyles $userAgentStyles = new UserAgentStyles(),
    ) {
    }

    /** @param list<StyleRule> $rules */
    public function computeTree(Node $root, array $rules): StyledNode
    {
        return $this->computeNode($root, $rules, null, [], []);
    }

    /** @param list<StyleRule> $rules @param list<Node> $ancestors @param array<string,string> $inheritedVariables */
    private function computeNode(Node $node, array $rules, ?ComputedStyle $parent, array $ancestors, array $inheritedVariables): StyledNode
    {
        $properties = $this->userAgentStyles->forNode($node);
        if ($parent !== null) {
            foreach (self::INHERITED as $property) {
                $value = $parent->get($property);
                if ($value !== null) {
                    $properties[$property] = $value;
                }
            }
        }

        $variables = $inheritedVariables;
        $winners = [];
        if ($node->type === 'element') {
            foreach ($rules as $rule) {
                if (!$this->selectorMatcher->matches($node, $rule->selector, $ancestors)) {
                    continue;
                }
                foreach ($rule->declarations as $property => $rawValue) {
                    [$value, $important] = $this->extractImportant($rawValue);
                    $this->considerWinner($winners, $property, $value, $important, $rule->specificity, $rule->sourceOrder, false);
                }
            }

            if ($node->inlineStyle() !== null) {
                foreach ($this->declarationParser->parseWithPriority($node->inlineStyle()) as $property => $entry) {
                    $this->considerWinner($winners, $property, $entry['value'], $entry['important'], 1000, PHP_INT_MAX, true);
                }
            }

            foreach ($winners as $property => $winner) {
                if (str_starts_with($property, '--')) {
                    $variables[$property] = $winner['value'];
                } else {
                    $properties[$property] = $winner['value'];
                }
            }
        }

        foreach ($properties as $property => $value) {
            $resolved = $this->resolveVariables($value, $variables);
            if ($resolved !== null) {
                $properties[$property] = $resolved;
            } else {
                unset($properties[$property]);
            }
        }
        foreach ($variables as $name => $value) {
            $resolved = $this->resolveVariables($value, $variables);
            if ($resolved !== null) {
                $variables[$name] = $resolved;
                $properties[$name] = $resolved;
            }
        }

        ksort($properties);
        $style = new ComputedStyle($properties);
        $children = [];
        $nextAncestors = $ancestors;
        if ($node->type === 'element') {
            $nextAncestors[] = $node;
        }
        foreach ($node->children as $child) {
            $children[] = $this->computeNode($child, $rules, $style, $nextAncestors, $variables);
        }

        return new StyledNode($node, $style, $children);
    }

    /** @param array<string,array{value:string,important:bool,specificity:int,sourceOrder:int,inline:bool}> $winners */
    private function considerWinner(array &$winners, string $property, string $value, bool $important, int $specificity, int $sourceOrder, bool $inline): void
    {
        $property = strtolower($property);
        $current = $winners[$property] ?? null;
        $candidate = compact('value', 'important', 'specificity', 'sourceOrder', 'inline');
        if ($current === null
            || ($important && !$current['important'])
            || ($important === $current['important'] && $specificity > $current['specificity'])
            || ($important === $current['important'] && $specificity === $current['specificity'] && $sourceOrder >= $current['sourceOrder'])) {
            $winners[$property] = $candidate;
        }
    }

    /** @return array{string,bool} */
    private function extractImportant(string $value): array
    {
        $important = preg_match('/!\s*important\s*$/i', $value) === 1;
        if ($important) {
            $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $value));
        }
        return [$value, $important];
    }

    /** @param array<string,string> $variables */
    private function resolveVariables(string $value, array $variables, int $depth = 0): ?string
    {
        if ($depth > 12) return null;
        if (!str_contains($value, 'var(')) return $value;

        $result = preg_replace_callback('/var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*([^\)]+))?\)/', function (array $m) use ($variables, $depth): string {
            $name = $m[1];
            $replacement = $variables[$name] ?? ($m[2] ?? '');
            return $this->resolveVariables(trim($replacement), $variables, $depth + 1) ?? '';
        }, $value);

        if ($result === null || trim($result) === '') return null;
        return trim($result);
    }
}
