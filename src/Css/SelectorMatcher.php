<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Dom\Node;

final class SelectorMatcher
{
    /** @param list<Node> $ancestors root..parent */
    public function matches(Node $node, string $selector, array $ancestors = []): bool
    {
        if ($node->type !== 'element') {
            return false;
        }

        $parts = $this->tokenize($selector);
        if ($parts === []) {
            return false;
        }

        $index = count($parts) - 1;
        if (!$this->matchesSimple($node, $parts[$index]['selector'])) {
            return false;
        }

        $ancestorIndex = count($ancestors) - 1;
        while ($index > 0) {
            $combinator = $parts[$index]['combinator'];
            $index--;
            $target = $parts[$index]['selector'];

            if ($combinator === '>') {
                if ($ancestorIndex < 0 || !$this->matchesSimple($ancestors[$ancestorIndex], $target)) {
                    return false;
                }
                $ancestorIndex--;
                continue;
            }

            $found = false;
            while ($ancestorIndex >= 0) {
                if ($this->matchesSimple($ancestors[$ancestorIndex], $target)) {
                    $found = true;
                    $ancestorIndex--;
                    break;
                }
                $ancestorIndex--;
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    public function specificity(string $selector): int
    {
        preg_match_all('/#[a-zA-Z0-9_-]+/', $selector, $ids);
        preg_match_all('/\.[a-zA-Z0-9_-]+/', $selector, $classes);
        preg_match_all('/\[[^\]]+\]/', $selector, $attributes);
        preg_match_all('/(?:^|[\s>])([a-zA-Z][a-zA-Z0-9_-]*|\*)/', trim($selector), $tags);
        $tagCount = count(array_filter($tags[1] ?? [], static fn(string $tag): bool => $tag !== '*'));

        return count($ids[0]) * 100 + (count($classes[0]) + count($attributes[0])) * 10 + $tagCount;
    }

    /** @return list<array{selector:string,combinator:?string}> */
    private function tokenize(string $selector): array
    {
        $selector = trim($selector);
        if ($selector === '' || str_contains($selector, ':') || preg_match('/[+~]/', $selector)) {
            return [];
        }

        $tokens = [];
        $buffer = '';
        $depth = 0;
        $pendingCombinator = null;
        $length = strlen($selector);

        for ($i = 0; $i < $length; $i++) {
            $ch = $selector[$i];
            if ($ch === '[') $depth++;
            if ($ch === ']') $depth--;

            if ($depth === 0 && ($ch === '>' || ctype_space($ch))) {
                if (trim($buffer) !== '') {
                    $tokens[] = ['selector' => trim($buffer), 'combinator' => $pendingCombinator];
                    $buffer = '';
                }
                if ($ch === '>') {
                    $pendingCombinator = '>';
                } elseif ($pendingCombinator === null) {
                    $pendingCombinator = ' ';
                }
                continue;
            }
            $buffer .= $ch;
        }

        if (trim($buffer) !== '') {
            $tokens[] = ['selector' => trim($buffer), 'combinator' => $pendingCombinator];
        }
        if ($tokens !== []) {
            $tokens[0]['combinator'] = null;
        }
        return $tokens;
    }

    private function matchesSimple(Node $node, string $selector): bool
    {
        if ($node->type !== 'element') return false;

        preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*|^\*/', $selector, $tagMatch);
        if ($tagMatch !== [] && $tagMatch[0] !== '*' && strtolower($tagMatch[0]) !== $node->tagName) {
            return false;
        }

        preg_match_all('/#([a-zA-Z0-9_-]+)/', $selector, $ids);
        foreach ($ids[1] as $id) {
            if ($node->id() !== $id) return false;
        }

        $classes = $node->classes();
        preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selector, $classMatches);
        foreach ($classMatches[1] as $class) {
            if (!in_array($class, $classes, true)) return false;
        }

        preg_match_all('/\[\s*([a-zA-Z_:][a-zA-Z0-9_:\-]*)(?:\s*(=|~=|\|=|\^=|\$=|\*=)\s*["\']?([^"\']*?)["\']?)?\s*\]/', $selector, $attrs, PREG_SET_ORDER);
        foreach ($attrs as $attr) {
            $actual = $node->attribute($attr[1]);
            if ($actual === null) return false;
            $operator = $attr[2] ?? '';
            if ($operator === '') continue;
            $expected = $attr[3] ?? '';
            $ok = match ($operator) {
                '=' => $actual === $expected,
                '~=' => in_array($expected, preg_split('/\s+/', trim($actual)) ?: [], true),
                '|=' => $actual === $expected || str_starts_with($actual, $expected . '-'),
                '^=' => str_starts_with($actual, $expected),
                '$=' => str_ends_with($actual, $expected),
                '*=' => str_contains($actual, $expected),
                default => false,
            };
            if (!$ok) return false;
        }

        return true;
    }
}
