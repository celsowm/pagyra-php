<?php

declare(strict_types=1);

namespace Pagyra\Style;

use Pagyra\Css\DeclarationParser;
use Pagyra\Css\SelectorMatcher;
use Pagyra\Css\StyleRule;
use Pagyra\Dom\Node;

final class StyleComputer
{
    /**
     * The properties an anonymous box has to carry down from the element it was generated for
     * (BlockLayoutEngine::anonymousTableBox()), which is why this is not private.
     */
    public const INHERITED = [
        'color', 'font-family', 'font-size', 'font-style', 'font-weight',
        'line-height', 'text-align', 'text-indent', 'visibility', 'white-space',
        'text-decoration', 'text-decoration-line',
        'x-link-href',
    ];

    /**
     * Initial values for the inherited properties this port understands, used by `initial` (and
     * only there: `revert`/`unset` want the UA value, which is what dropping the declaration
     * already gives). `font-family` is deliberately absent — the port has no single named default
     * family to reset to, and no corpus document asks for it.
     */
    private const INITIAL = [
        'color' => '#000000',
        'font-size' => 'medium',
        'font-style' => 'normal',
        'font-weight' => 'normal',
        'line-height' => 'normal',
        'text-align' => 'start',
        'text-indent' => '0',
        'visibility' => 'visible',
        'white-space' => 'normal',
        'text-decoration' => 'none',
        'text-decoration-line' => 'none',
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

        $hints = [];

        if ($node->isElement('table')) {
            $width = $this->borderAttributeWidth($node);
            if ($width !== null) {
                $hints = ['border-style' => 'solid', 'border-width' => $width . 'px', 'border-color' => '#808080'];
            }
        } elseif ($node->isElement('td') || $node->isElement('th')) {
            for ($i = count($ancestors) - 1; $i >= 0; $i--) {
                if (!$ancestors[$i]->isElement('table')) {
                    continue;
                }
                if ($this->borderAttributeWidth($ancestors[$i]) !== null) {
                    $hints = ['border-style' => 'solid', 'border-width' => '1px', 'border-color' => '#808080'];
                }
                break;
            }
        }

        if ($node->isElement('table') || $node->isElement('td') || $node->isElement('th') || $node->isElement('col')) {
            foreach (['width', 'height'] as $property) {
                $value = $this->dimensionAttribute($node, $property);
                if ($value !== null) {
                    $hints[$property] = $value;
                }
            }
        }

        foreach ($this->remainingHints($node, $ancestors) as $property => $value) {
            $hints[$property] = $value;
        }

        return $hints;
    }

    /**
     * The rest of the HTML Standard's presentational hints that these documents actually rely on,
     * measured against the corpus rather than transcribed wholesale from the spec.
     *
     * `align` is the one that decides anything: 1622 `<p align>` in 422 documents, though in 1447
     * of those the same element also carries `text-align` in its `style`, which beats a hint — so
     * 25 documents are the ones where dropping it left a heading flush left that should have been
     * centred. `cellpadding`/`cellspacing` (12 and 24 documents) matter because without them
     * every cell falls back to the UA sheet's 8px padding, so a grid written `cellpadding="0"`
     * came out far looser than the wkhtmltopdf output. `hr size` (30 documents) is the rule's
     * thickness, and `hspace`/`vspace`/`border` on `<img>` (46 documents) its margins and frame.
     *
     * These are hints, so they sit with the UA defaults and any author declaration still wins.
     *
     * @param list<Node> $ancestors
     * @return array<string,string>
     */
    private function remainingHints(Node $node, array $ancestors): array
    {
        $hints = [];

        // `align` maps to `text-align` on a block container, and the two edge values are the
        // ones the spec spells out; `middle` is `center`. On <img> it is a float instead, which
        // is left alone here: no corpus document depends on it and floating an image is a much
        // larger behavioural change than aligning text.
        if (!$node->isElement('img')) {
            $align = strtolower(trim($node->attribute('align') ?? ''));
            $textAlign = match ($align) {
                'center', 'middle' => 'center',
                'left' => 'left',
                'right' => 'right',
                'justify' => 'justify',
                default => null,
            };
            if ($textAlign !== null) {
                $hints['text-align'] = $textAlign;
            }
        }

        if ($node->isElement('td') || $node->isElement('th')) {
            $padding = $this->nonNegativeIntegerAttribute($this->nearestTable($ancestors), 'cellpadding');
            if ($padding !== null) {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    $hints['padding-' . $side] = $padding . 'px';
                }
            }
            $valign = strtolower(trim($node->attribute('valign') ?? ''));
            if (in_array($valign, ['top', 'middle', 'bottom', 'baseline'], true)) {
                $hints['vertical-align'] = $valign;
            }
        }

        if ($node->isElement('table')) {
            $spacing = $this->nonNegativeIntegerAttribute($node, 'cellspacing');
            if ($spacing !== null) {
                $hints['border-spacing'] = $spacing . 'px';
            }
        }

        if ($node->isElement('hr')) {
            $size = $this->nonNegativeIntegerAttribute($node, 'size');
            if ($size !== null && $size > 0) {
                $hints['border-top-width'] = $size . 'px';
            }
        }

        if ($node->isElement('img')) {
            $hspace = $this->nonNegativeIntegerAttribute($node, 'hspace');
            if ($hspace !== null) {
                $hints['margin-left'] = $hspace . 'px';
                $hints['margin-right'] = $hspace . 'px';
            }
            $vspace = $this->nonNegativeIntegerAttribute($node, 'vspace');
            if ($vspace !== null) {
                $hints['margin-top'] = $vspace . 'px';
                $hints['margin-bottom'] = $vspace . 'px';
            }
            $border = $this->nonNegativeIntegerAttribute($node, 'border');
            if ($border !== null) {
                $hints['border-width'] = $border . 'px';
                $hints['border-style'] = $border > 0 ? 'solid' : 'none';
            }
        }

        return $hints;
    }

    /** @param list<Node> $ancestors */
    private function nearestTable(array $ancestors): ?Node
    {
        for ($i = count($ancestors) - 1; $i >= 0; $i--) {
            if ($ancestors[$i]->isElement('table')) {
                return $ancestors[$i];
            }
        }

        return null;
    }

    /** The attribute as a non-negative integer, or null when absent or not one. */
    private function nonNegativeIntegerAttribute(?Node $node, string $name): ?int
    {
        if ($node === null) {
            return null;
        }
        $raw = trim($node->attribute($name) ?? '');

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * The `width`/`height` content attributes as a CSS length: a bare number is pixels, a trailing
     * `%` stays a percentage, anything else is ignored. These carry the column proportions of real
     * tables — the requisição grids of JFRJ/EPROC1 are `<table width="100%">` whose cells declare
     * `width="378"` and `width="227"` and no CSS width at all, so dropping the attribute left the
     * columns to be guessed from their text and put the dividing rule in the wrong place.
     */
    private function dimensionAttribute(Node $node, string $name): ?string
    {
        $raw = trim($node->attribute($name) ?? '');
        if ($raw === '') {
            return null;
        }
        if (str_ends_with($raw, '%')) {
            $number = trim(substr($raw, 0, -1));
            return is_numeric($number) && (float) $number >= 0 ? $number . '%' : null;
        }

        return is_numeric($raw) && (float) $raw >= 0 ? $raw . 'px' : null;
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


    /**
     * The CSS-wide keywords (`inherit`, `initial`, `unset`, `revert`), which no part of this port
     * understood: the literal token was stored as if it were a value and carried all the way to
     * the paint layer. `font-family: inherit` ended up as a family named "inherit", which matches
     * nothing and drops the run into Times; `font-style: inherit` produced a font style of
     * "inherit" instead of italic; `font-weight: inherit` inside a bold parent resolved to 400;
     * `color: inherit` produced no colour at all; and `line-height: inherit` put a span on a
     * different baseline from the text beside it on the same line.
     *
     * These are not exotic: 19 corpus documents carry 1368 of these declarations on the visual
     * properties alone, 36 to 45 per document, because they are what a browser writes into the
     * `style` attribute when text is pasted into the editors these systems use.
     *
     * The reference handles the keyword per property (font-weight.ts, compute-style/decoration.ts)
     * rather than generally, so AGENTS.md's item 5 applies and this follows CSS Cascade: `inherit`
     * takes the parent's computed value; `initial` and `revert` drop the author declaration, which
     * in this port means falling back to whatever the UA sheet or a presentational hint already
     * put there (the port has no table of per-property initial values, and for these documents the
     * UA value is what both keywords should land on anyway); `unset` is `inherit` for an inherited
     * property and the same drop for every other one.
     *
     * @param array<string,string> $properties the cascade so far, UA and hints already applied
     * @return bool whether the value was a CSS-wide keyword and has been dealt with here
     */
    private function applyCssWideKeyword(array &$properties, string $property, string $value, ?ComputedStyle $parent): bool
    {
        $keyword = strtolower(trim($value));
        if (!in_array($keyword, ['inherit', 'initial', 'unset', 'revert'], true)) {
            return false;
        }

        $inheritsByDefault = in_array($property, self::INHERITED, true);
        $takesParentValue = $keyword === 'inherit' || ($keyword === 'unset' && $inheritsByDefault);

        if (!$takesParentValue) {
            // `initial` asks for the property's own initial value, which for an inherited
            // property is not at all the same as dropping the declaration: dropping would leave
            // the value the parent handed down. `text-decoration-line: initial` is exactly that
            // case in the corpus (334 declarations across 24 documents) and would keep the
            // parent's underline instead of clearing it. So the inherited properties whose
            // initial value this port can name are reset explicitly, and everything else falls
            // back to dropping the author declaration — which is what `revert` means anyway and
            // what `initial` lands on for a non-inherited property, since the UA sheet is the
            // only thing underneath.
            if ($keyword === 'initial' && array_key_exists($property, self::INITIAL)) {
                $properties[$property] = self::INITIAL[$property];
            }

            return true;
        }

        $parentValue = $parent?->get($property);
        if ($parentValue !== null) {
            $properties[$property] = $parentValue;
        } else {
            unset($properties[$property]);
        }

        return true;
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
                } elseif (!$this->applyCssWideKeyword($properties, $property, $winner['value'], $parent)) {
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
