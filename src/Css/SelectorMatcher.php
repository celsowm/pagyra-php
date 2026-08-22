<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Dom\Node;

final class SelectorMatcher
{
    public function matches(Node $node, string $selector): bool
    {
        if ($node->type !== 'element') {
            return false;
        }

        $selector = trim($selector);
        if ($selector === '' || preg_match('/[\s>+~:\[]/', $selector) === 1) {
            return false;
        }

        preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*/', $selector, $tagMatch);
        if ($tagMatch !== [] && strtolower($tagMatch[0]) !== $node->tagName) {
            return false;
        }

        if (preg_match_all('/#([a-zA-Z0-9_-]+)/', $selector, $ids) && $ids[1] !== []) {
            foreach ($ids[1] as $id) {
                if ($node->id() !== $id) {
                    return false;
                }
            }
        }

        $classes = $node->classes();
        if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selector, $classMatches)) {
            foreach ($classMatches[1] as $class) {
                if (!in_array($class, $classes, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function specificity(string $selector): int
    {
        preg_match_all('/#[a-zA-Z0-9_-]+/', $selector, $ids);
        preg_match_all('/\.[a-zA-Z0-9_-]+/', $selector, $classes);
        $tag = preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*/', trim($selector)) === 1 ? 1 : 0;

        return count($ids[0]) * 100 + count($classes[0]) * 10 + $tag;
    }
}
