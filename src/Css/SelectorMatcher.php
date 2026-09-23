<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Dom\Node;

/**
 * Selectors Level 4 matching for the subset the reference implements (pagyra-js
 * `src/css/selectors/parser.ts` and `matcher.ts`): type, universal, `#id`, `.class`, attribute
 * selectors with every operator and the `i`/`s` flags, the four combinators (descendant, `>`,
 * `+`, `~`), the structural pseudo-classes (`:first-child`, `:last-child`, `:only-child`,
 * `:nth-child()`, `:nth-last-child()` and the `-of-type` family), `:empty`, `:root`, `:not()`,
 * `:is()`/`:where()`, plus `:link`/`:any-link`, `:checked`, `:disabled`/`:enabled` and `:lang()`
 * because they are decidable from the markup alone.
 *
 * The previous matcher refused any selector containing `:`, `+` or `~` outright, so every rule
 * with a pseudo-class or a sibling combinator was dead, and it read classes with a regex over the
 * whole compound, which made `a[href$=".pdf"]` demand a class named "pdf".
 *
 * Interaction pseudo-classes (`:hover`, `:focus`, `:active`, `:visited`, `:target`...) never match
 * in a PDF. A selector that ends in a pseudo-element (`::before`, legacy `:after`) does not match
 * the element itself; `pseudoElement()` tells the caller which one it targets.
 */
final class SelectorMatcher
{
    private const PSEUDO_ELEMENTS = ['before', 'after', 'first-line', 'first-letter', 'marker', 'selection', 'placeholder', 'backdrop'];

    private const NEVER_MATCHING = [
        'hover', 'focus', 'focus-within', 'focus-visible', 'active', 'visited', 'target', 'target-within',
        'current', 'past', 'future', 'playing', 'paused', 'autofill', 'fullscreen', 'modal', 'picture-in-picture',
        'placeholder-shown', 'user-invalid', 'user-valid', 'invalid', 'default', 'indeterminate', 'has', 'host', 'defined',
    ];

    /** @var array<string,?array> parsed complex selectors by source text */
    private array $cache = [];

    /** @param list<Node> $ancestors root..parent */
    public function matches(Node $node, string $selector, array $ancestors = []): bool
    {
        if ($node->type !== 'element') {
            return false;
        }

        $complex = $this->parse($selector);
        if ($complex === null || $complex['pseudoElement'] !== null) {
            return false;
        }

        return $this->matchComplex($complex['compounds'], count($complex['compounds']) - 1, $node, $ancestors);
    }

    /** The pseudo-element a selector targets (`before`, `after`...), or null when it targets the element. */
    public function pseudoElement(string $selector): ?string
    {
        return $this->parse($selector)['pseudoElement'] ?? null;
    }

    /** Whether the selector minus its pseudo-element matches the element (`p.x::before` → `p.x`). */
    public function matchesOriginating(Node $node, string $selector, array $ancestors = []): bool
    {
        if ($node->type !== 'element') {
            return false;
        }
        $complex = $this->parse($selector);
        if ($complex === null) {
            return false;
        }

        return $this->matchComplex($complex['compounds'], count($complex['compounds']) - 1, $node, $ancestors);
    }

    /**
     * Specificity as `ids * 100 + (classes + attributes + pseudo-classes) * 10 + (types +
     * pseudo-elements)`, the same packing as before so author rules keep ranking below inline
     * style (1000). `:not()`/`:is()` count as their most specific argument, `:where()` as zero.
     */
    public function specificity(string $selector): int
    {
        $complex = $this->parse($selector);
        if ($complex === null) {
            return 0;
        }
        [$a, $b, $c] = $this->complexSpecificity($complex);

        return $a * 100 + $b * 10 + $c;
    }

    /** @return array{0:int,1:int,2:int} */
    private function complexSpecificity(array $complex): array
    {
        $a = $b = $c = 0;
        foreach ($complex['compounds'] as $compound) {
            [$ca, $cb, $cc] = $this->compoundSpecificity($compound);
            $a += $ca;
            $b += $cb;
            $c += $cc;
        }
        if ($complex['pseudoElement'] !== null) {
            $c++;
        }

        return [$a, $b, $c];
    }

    /** @return array{0:int,1:int,2:int} */
    private function compoundSpecificity(array $compound): array
    {
        $a = count($compound['ids']);
        $b = count($compound['classes']) + count($compound['attributes']);
        $c = $compound['tag'] !== null ? 1 : 0;
        foreach ($compound['pseudos'] as $pseudo) {
            if ($pseudo['name'] === 'where') {
                continue;
            }
            if (in_array($pseudo['name'], ['not', 'is', 'matches', '-webkit-any', 'any'], true)) {
                $best = [0, 0, 0];
                foreach ($pseudo['selectors'] as $inner) {
                    $candidate = $this->complexSpecificity($inner);
                    if ($candidate > $best) {
                        $best = $candidate;
                    }
                }
                $a += $best[0];
                $b += $best[1];
                $c += $best[2];
                continue;
            }
            $b++;
        }

        return [$a, $b, $c];
    }

    /** @param list<array> $compounds @param list<Node> $ancestors */
    private function matchComplex(array $compounds, int $index, Node $node, array $ancestors): bool
    {
        if (!$this->matchCompound($compounds[$index], $node, $ancestors)) {
            return false;
        }
        if ($index === 0) {
            return true;
        }

        $combinator = $compounds[$index]['combinator'];
        $last = count($ancestors) - 1;
        switch ($combinator) {
            case '>':
                return $last >= 0 && $this->matchComplex($compounds, $index - 1, $ancestors[$last], array_slice($ancestors, 0, $last));
            case '+':
                $previous = $this->previousElementSiblings($node, $ancestors);
                return $previous !== [] && $this->matchComplex($compounds, $index - 1, $previous[0], $ancestors);
            case '~':
                foreach ($this->previousElementSiblings($node, $ancestors) as $sibling) {
                    if ($this->matchComplex($compounds, $index - 1, $sibling, $ancestors)) {
                        return true;
                    }
                }
                return false;
            default:
                for ($k = $last; $k >= 0; $k--) {
                    if ($this->matchComplex($compounds, $index - 1, $ancestors[$k], array_slice($ancestors, 0, $k))) {
                        return true;
                    }
                }
                return false;
        }
    }

    /** @param list<Node> $ancestors */
    private function matchCompound(array $compound, Node $node, array $ancestors): bool
    {
        if ($node->type !== 'element') {
            return false;
        }
        if ($compound['tag'] !== null && $compound['tag'] !== $node->tagName) {
            return false;
        }
        foreach ($compound['ids'] as $id) {
            if ($node->id() !== $id) {
                return false;
            }
        }
        if ($compound['classes'] !== []) {
            $classes = $node->classes();
            foreach ($compound['classes'] as $class) {
                if (!in_array($class, $classes, true)) {
                    return false;
                }
            }
        }
        foreach ($compound['attributes'] as $attribute) {
            if (!$this->matchAttribute($node, $attribute)) {
                return false;
            }
        }
        foreach ($compound['pseudos'] as $pseudo) {
            if (!$this->matchPseudo($pseudo, $node, $ancestors)) {
                return false;
            }
        }

        return true;
    }

    private function matchAttribute(Node $node, array $attribute): bool
    {
        $actual = $node->attributes[$attribute['name']] ?? null;
        if ($actual === null) {
            return false;
        }
        if ($attribute['operator'] === null) {
            return true;
        }
        $expected = $attribute['value'];
        if ($attribute['insensitive']) {
            $actual = strtolower($actual);
            $expected = strtolower($expected);
        }

        return match ($attribute['operator']) {
            '=' => $actual === $expected,
            '~=' => $expected !== '' && in_array($expected, preg_split('/\s+/', trim($actual)) ?: [], true),
            '|=' => $actual === $expected || str_starts_with($actual, $expected . '-'),
            '^=' => $expected !== '' && str_starts_with($actual, $expected),
            '$=' => $expected !== '' && str_ends_with($actual, $expected),
            '*=' => $expected !== '' && str_contains($actual, $expected),
            default => false,
        };
    }

    /** @param list<Node> $ancestors */
    private function matchPseudo(array $pseudo, Node $node, array $ancestors): bool
    {
        $name = $pseudo['name'];
        switch ($name) {
            case 'first-child':
                return $this->elementIndex($node, $ancestors, false, false) === 1;
            case 'last-child':
                return $this->elementIndex($node, $ancestors, false, true) === 1;
            case 'only-child':
                return $this->elementIndex($node, $ancestors, false, false) === 1 && $this->elementIndex($node, $ancestors, false, true) === 1;
            case 'nth-child':
                return $this->matchesNth($this->elementIndex($node, $ancestors, false, false), $pseudo['nth']);
            case 'nth-last-child':
                return $this->matchesNth($this->elementIndex($node, $ancestors, false, true), $pseudo['nth']);
            case 'first-of-type':
                return $this->elementIndex($node, $ancestors, true, false) === 1;
            case 'last-of-type':
                return $this->elementIndex($node, $ancestors, true, true) === 1;
            case 'only-of-type':
                return $this->elementIndex($node, $ancestors, true, false) === 1 && $this->elementIndex($node, $ancestors, true, true) === 1;
            case 'nth-of-type':
                return $this->matchesNth($this->elementIndex($node, $ancestors, true, false), $pseudo['nth']);
            case 'nth-last-of-type':
                return $this->matchesNth($this->elementIndex($node, $ancestors, true, true), $pseudo['nth']);
            case 'empty':
                foreach ($node->children as $child) {
                    if ($child->type === 'element' || ($child->type === 'text' && ($child->text ?? '') !== '')) {
                        return false;
                    }
                }
                return true;
            case 'root':
                return $node->tagName === 'html';
            case 'not':
                foreach ($pseudo['selectors'] as $inner) {
                    if ($inner['pseudoElement'] === null && $this->matchComplex($inner['compounds'], count($inner['compounds']) - 1, $node, $ancestors)) {
                        return false;
                    }
                }
                return true;
            case 'is':
            case 'where':
            case 'matches':
            case 'any':
            case '-webkit-any':
                foreach ($pseudo['selectors'] as $inner) {
                    if ($inner['pseudoElement'] === null && $this->matchComplex($inner['compounds'], count($inner['compounds']) - 1, $node, $ancestors)) {
                        return true;
                    }
                }
                return false;
            case 'link':
            case 'any-link':
                return in_array($node->tagName, ['a', 'area'], true) && $node->attribute('href') !== null;
            case 'checked':
                return ($node->tagName === 'input' && $node->attribute('checked') !== null)
                    || ($node->tagName === 'option' && $node->attribute('selected') !== null);
            case 'disabled':
                return $node->attribute('disabled') !== null;
            case 'enabled':
                return in_array($node->tagName, ['input', 'button', 'select', 'textarea', 'option', 'fieldset'], true) && $node->attribute('disabled') === null;
            case 'lang':
                $wanted = strtolower($pseudo['argument']);
                foreach (array_merge([$node], array_reverse($ancestors)) as $candidate) {
                    $lang = $candidate->attribute('lang') ?? $candidate->attribute('xml:lang');
                    if ($lang !== null) {
                        $lang = strtolower($lang);
                        return $wanted !== '' && ($lang === $wanted || str_starts_with($lang, $wanted . '-'));
                    }
                }
                return false;
            default:
                return false;
        }
    }

    /**
     * 1-based position of the element among its element siblings (optionally only those of its
     * own type), counted from the start or from the end. An element without a parent in this
     * tree is its own only sibling.
     *
     * @param list<Node> $ancestors
     */
    private function elementIndex(Node $node, array $ancestors, bool $ofType, bool $fromEnd): int
    {
        $siblings = $ancestors === [] ? [$node] : $ancestors[count($ancestors) - 1]->children;
        if ($fromEnd) {
            $siblings = array_reverse($siblings);
        }
        $index = 0;
        foreach ($siblings as $sibling) {
            if ($sibling->type !== 'element' || ($ofType && $sibling->tagName !== $node->tagName)) {
                continue;
            }
            $index++;
            if ($sibling === $node) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * The element siblings before this one, nearest first.
     *
     * @param list<Node> $ancestors
     * @return list<Node>
     */
    private function previousElementSiblings(Node $node, array $ancestors): array
    {
        if ($ancestors === []) {
            return [];
        }
        $previous = [];
        foreach ($ancestors[count($ancestors) - 1]->children as $sibling) {
            if ($sibling === $node) {
                return array_reverse($previous);
            }
            if ($sibling->type === 'element') {
                $previous[] = $sibling;
            }
        }

        return [];
    }

    /** @param array{0:int,1:int} $nth */
    private function matchesNth(int $index, array $nth): bool
    {
        [$a, $b] = $nth;
        if ($index < 1) {
            return false;
        }
        if ($a === 0) {
            return $index === $b;
        }
        $steps = ($index - $b) / $a;

        return $steps >= 0 && ($index - $b) % $a === 0;
    }

    /** @return array{compounds:list<array>,pseudoElement:?string}|null */
    private function parse(string $selector): ?array
    {
        if (array_key_exists($selector, $this->cache)) {
            return $this->cache[$selector];
        }
        $parser = new SelectorSyntax($selector);
        $parsed = $parser->complexSelector();

        return $this->cache[$selector] = $parsed;
    }

    /** @internal used by SelectorSyntax to classify `:before`-style legacy pseudo-elements */
    public static function isPseudoElementName(string $name): bool
    {
        return in_array($name, self::PSEUDO_ELEMENTS, true);
    }

    /** @internal */
    public static function neverMatches(string $name): bool
    {
        return in_array($name, self::NEVER_MATCHING, true);
    }
}
