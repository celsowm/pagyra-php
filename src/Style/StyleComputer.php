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
        'line-height', 'text-align', 'text-indent', 'visibility', 'white-space',
        'text-decoration', 'text-decoration-line',
        'x-link-href',
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

    /**
     * The `border` content attribute of `<table>`, which the HTML Standard's rendering section
     * maps to CSS ("Tables" > presentational hints): a table with a valid non-zero border gets
     * that width, and every cell in it gets a 1px border of its own regardless of the value.
     * These are hints, not author CSS — they sit with the UA defaults so any real declaration
     * (stylesheet or inline `style`) still wins.
     *
     * Real judicial documents rely on this constantly: `<table border="1">` with no border
     * anywhere in the CSS is the single most common table in the corpus, and without the hint
     * every one of those grids renders with no rules at all, which is nothing like what the
     * wkhtmltopdf output (WebKit, which implements the hint) shows.
     *
     * Two deliberate departures from the spec's exact mapping, both because the paint layer
     * would otherwise drop the border entirely: the style is `solid` rather than the spec's
     * `outset`/`inset`, since DisplayListBuilder only paints `solid` today and an unpaintable
     * style would leave the grid invisible again; and the color is pinned to a neutral grey
     * instead of being left to `currentcolor`, which in these documents is frequently the blue
     * of the surrounding heading text and would paint blue grids.
     *
     * @param list<Node> $ancestors
     * @return array<string,string>
     */
    private function presentationalHints(Node $node, array $ancestors): array
    {
        if ($node->type !== 'element') {
            return [];
        }

        if ($node->isElement('table')) {
            $width = $this->borderAttributeWidth($node);
            return $width === null ? [] : [
                'border-style' => 'solid',
                'border-width' => $width . 'px',
                'border-color' => '#808080',
            ];
        }

        if (!$node->isElement('td') && !$node->isElement('th')) {
            return [];
        }

        for ($i = count($ancestors) - 1; $i >= 0; $i--) {
            if (!$ancestors[$i]->isElement('table')) {
                continue;
            }
            return $this->borderAttributeWidth($ancestors[$i]) === null ? [] : [
                'border-style' => 'solid',
                'border-width' => '1px',
                'border-color' => '#808080',
            ];
        }

        return [];
    }

    /** The attribute's value as a positive pixel width, or null when absent, invalid or zero. */
    private function borderAttributeWidth(Node $table): ?int
    {
        $raw = trim($table->attribute('border') ?? '');
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $width = (int) $raw;

        return $width > 0 ? $width : null;
    }

    /** @param list<StyleRule> $rules @param list<Node> $ancestors @param array<string,string> $inheritedVariables */
    private function computeNode(Node $node, array $rules, ?ComputedStyle $parent, array $ancestors, array $inheritedVariables): StyledNode
    {
        $properties = $this->userAgentStyles->forNode($node);
        foreach ($this->presentationalHints($node, $ancestors) as $property => $value) {
            $properties[$property] = $value;
        }
        if ($parent !== null) {
            foreach (self::INHERITED as $property) {
                $value = $parent->get($property);
                // Inheritance only fills properties the UA stylesheet did not set on this
                // element; a UA element rule (e.g. `a { color: #0000EE }`, `strong { font-weight:
                // bold }`) still beats the inherited value, as it does in the real cascade.
                if ($value !== null && !array_key_exists($property, $properties)) {
                    $properties[$property] = $value;
                }
            }
        }
        if ($node->isElement('a')) {
            $href = trim($node->attribute('href') ?? '');
            if ($href !== '') {
                // Not a real CSS property: piggybacks on the inherited-property mechanism so
                // descendant text runs (e.g. a <span> inside <a>) can still resolve which link,
                // if any, they belong to, and the node's own href always wins over whatever it
                // inherited (relevant only for the invalid-HTML case of a nested <a>). See
                // DisplayListBuilder/PdfSerializer for where this becomes an actual clickable
                // PDF link annotation.
                $properties['x-link-href'] = $href;
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

        if ($node->isElement('li')) {
            $parentNode = $ancestors === [] ? null : $ancestors[array_key_last($ancestors)];
            $marker = $this->computeListMarker($node, $parentNode, $properties, $parent);
            if ($marker !== null) {
                // Not a real CSS property: carries the already-formatted marker string to
                // DisplayListBuilder, which paints it in the list's left padding. pagyra-js
                // builds the same marker run in pdf/utils/list-utils.ts.
                $properties['x-list-marker'] = $marker;
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

    /** @param array<string,string> $properties */
    private function computeListMarker(Node $li, ?Node $parent, array $properties, ?ComputedStyle $parentStyle): ?string
    {
        if ($parent === null || $parent->type !== 'element') return null;
        $parentTag = $parent->tagName ?? '';
        if ($parentTag !== 'ol' && $parentTag !== 'ul') return null;

        $own = ListMarker::normalizeType(
            $properties['list-style-type'] ?? $this->listStyleShorthandType($properties['list-style'] ?? null),
        );
        $inherited = ListMarker::normalizeType(
            $parentStyle?->get('list-style-type') ?? $this->listStyleShorthandType($parentStyle?->get('list-style')),
        );
        $type = ListMarker::resolveType($own, $inherited, $parentTag);
        if ($type === null || $type === 'none') return null;

        $index = ListMarker::isOrdered($type) ? $this->computeListItemIndex($li, $parent) : 1;
        return ListMarker::format($type, $index);
    }

    private function computeListItemIndex(Node $li, Node $parent): int
    {
        $counter = 0;
        $start = trim((string) $parent->attribute('start'));
        if ($start !== '' && preg_match('/^-?\d+$/', $start) === 1) {
            $counter = (int) $start - 1;
        }
        foreach ($parent->children as $child) {
            if ($child->type !== 'element' || $child->tagName !== 'li') continue;
            $value = trim((string) $child->attribute('value'));
            if ($value !== '' && preg_match('/^-?\d+$/', $value) === 1) {
                $counter = (int) $value;
            } else {
                $counter++;
            }
            if ($child === $li) return $counter;
        }
        return max($counter, 1);
    }

    private function listStyleShorthandType(?string $shorthand): ?string
    {
        if ($shorthand === null) return null;
        $keywords = [
            'none', 'disc', 'circle', 'square', 'decimal', 'decimal-leading-zero',
            'lower-alpha', 'lower-latin', 'upper-alpha', 'upper-latin', 'lower-roman', 'upper-roman',
        ];
        foreach (preg_split('/\s+/', strtolower(trim($shorthand))) ?: [] as $token) {
            if (in_array($token, $keywords, true)) return $token;
        }
        return null;
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
