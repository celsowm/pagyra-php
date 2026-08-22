<?php

declare(strict_types=1);

namespace Pagyra\Css;

final class StylesheetParser
{
    public function __construct(
        private readonly DeclarationParser $declarationParser = new DeclarationParser(),
        private readonly SelectorMatcher $selectorMatcher = new SelectorMatcher(),
        private readonly MediaQueryEvaluator $mediaQueryEvaluator = new MediaQueryEvaluator(),
    ) {
    }

    /** @return list<StyleRule> */
    public function parse(
        string $css,
        string $mediaType = 'print',
        ?float $viewportWidth = null,
        ?float $viewportHeight = null,
    ): array {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $rules = [];
        $sourceOrder = 0;
        $this->processBlocks($css, $rules, $sourceOrder, $mediaType, $viewportWidth, $viewportHeight);
        return $rules;
    }

    /** @param list<StyleRule> $rules */
    private function processBlocks(
        string $css,
        array &$rules,
        int &$sourceOrder,
        string $mediaType,
        ?float $viewportWidth,
        ?float $viewportHeight,
    ): void {
        $length = strlen($css);
        $cursor = 0;

        while ($cursor < $length) {
            while ($cursor < $length && ctype_space($css[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $length) {
                break;
            }

            $open = $this->findNextOpenBrace($css, $cursor);
            if ($open === null) {
                break;
            }

            $prelude = trim(substr($css, $cursor, $open - $cursor));
            $close = $this->findMatchingBrace($css, $open);
            if ($close === null) {
                break;
            }

            $body = substr($css, $open + 1, $close - $open - 1);
            $cursor = $close + 1;
            if ($prelude === '') {
                continue;
            }

            if (preg_match('/^@media\s+(.+)$/is', $prelude, $mediaMatch) === 1) {
                if ($this->mediaQueryEvaluator->matches($mediaMatch[1], $mediaType, $viewportWidth, $viewportHeight)) {
                    $this->processBlocks($body, $rules, $sourceOrder, $mediaType, $viewportWidth, $viewportHeight);
                }
                continue;
            }

            if (str_starts_with($prelude, '@')) {
                continue;
            }

            $parsed = $this->declarationParser->parseWithPriority($body);
            if ($parsed === []) {
                continue;
            }

            $declarations = [];
            foreach ($parsed as $property => $entry) {
                $declarations[$property] = $entry['value'] . ($entry['important'] ? ' !important' : '');
            }

            $ruleOrder = $sourceOrder++;
            foreach ($this->splitSelectors($prelude) as $selector) {
                $rules[] = new StyleRule(
                    selector: $selector,
                    declarations: $declarations,
                    sourceOrder: $ruleOrder,
                    specificity: $this->selectorMatcher->specificity($selector),
                );
            }
        }
    }

    private function findNextOpenBrace(string $css, int $start): ?int
    {
        $quote = null;
        $escaped = false;
        $length = strlen($css);

        for ($i = $start; $i < $length; $i++) {
            $ch = $css[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '{') {
                return $i;
            }
        }
        return null;
    }

    private function findMatchingBrace(string $css, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($css);

        for ($i = $open; $i < $length; $i++) {
            $ch = $css[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /** @return list<string> */
    private function splitSelectors(string $selectorText): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $selectorText)),
            static fn(string $selector): bool => $selector !== '',
        ));
    }
}
