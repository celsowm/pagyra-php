<?php

declare(strict_types=1);

namespace Pagyra\Css;

final class StylesheetParser
{
    public function __construct(
        private readonly DeclarationParser $declarationParser = new DeclarationParser(),
        private readonly SelectorMatcher $selectorMatcher = new SelectorMatcher(),
    ) {
    }

    /** @return list<StyleRule> */
    public function parse(string $css): array
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $rules = [];
        $sourceOrder = 0;

        if (!preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $selectorText = trim($match[1]);
            if ($selectorText === '' || str_starts_with($selectorText, '@')) {
                continue;
            }
            $declarations = $this->declarationParser->parse($match[2]);
            if ($declarations === []) {
                continue;
            }

            foreach (explode(',', $selectorText) as $selector) {
                $selector = trim($selector);
                if ($selector === '') {
                    continue;
                }
                $rules[] = new StyleRule(
                    selector: $selector,
                    declarations: $declarations,
                    sourceOrder: $sourceOrder++,
                    specificity: $this->selectorMatcher->specificity($selector),
                );
            }
        }

        return $rules;
    }
}
