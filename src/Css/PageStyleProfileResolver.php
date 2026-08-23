<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Units\Units;

final class PageStyleProfileResolver
{
    public function __construct(
        private readonly PageStyleResolver $defaultResolver = new PageStyleResolver(),
        private readonly MediaQueryEvaluator $mediaQueryEvaluator = new MediaQueryEvaluator(),
    ) {
    }

    /**
     * @param array{top:float,right:float,bottom:float,left:float} $fallbackMargins
     * @return array{width:float,height:float,margins:array{default:array{top:float,right:float,bottom:float,left:float},first:array{top:float,right:float,bottom:float,left:float},left:array{top:float,right:float,bottom:float,left:float},right:array{top:float,right:float,bottom:float,left:float}}}
     */
    public function resolve(
        string $css,
        float $fallbackWidth,
        float $fallbackHeight,
        array $fallbackMargins,
        string $mediaType = 'print',
        ?float $viewportWidth = null,
        ?float $viewportHeight = null,
    ): array {
        $default = $this->defaultResolver->resolve(
            $css,
            $fallbackWidth,
            $fallbackHeight,
            $fallbackMargins,
            $mediaType,
            $viewportWidth,
            $viewportHeight,
        );

        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $rules = [];
        $sourceOrder = 0;
        $this->collectPageRules($css, $rules, $sourceOrder, $mediaType, $viewportWidth, $viewportHeight);

        $profile = [];
        foreach ([
            'default' => [],
            'first' => ['first', 'right'],
            'left' => ['left'],
            'right' => ['right'],
        ] as $variant => $pseudoClasses) {
            $profile[$variant] = $this->resolveMarginsForVariant(
                $rules,
                $pseudoClasses,
                $fallbackMargins,
                $default['width'],
                $default['height'],
            );
        }

        return [
            'width' => $default['width'],
            'height' => $default['height'],
            'margins' => $profile,
        ];
    }

    /**
     * @param list<array{selectors:list<string>,body:string,sourceOrder:int}> $rules
     * @param list<string> $pseudoClasses
     * @param array{top:float,right:float,bottom:float,left:float} $fallbackMargins
     * @return array{top:float,right:float,bottom:float,left:float}
     */
    private function resolveMarginsForVariant(array $rules, array $pseudoClasses, array $fallbackMargins, float $pageWidth, float $pageHeight): array
    {
        $candidates = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $candidates[$side] = [
                'value' => (float) $fallbackMargins[$side],
                'important' => false,
                'specificity' => -1,
                'ruleOrder' => -1,
                'declarationOrder' => -1,
            ];
        }

        foreach ($rules as $rule) {
            $specificity = $this->matchingSpecificity($rule['selectors'], $pseudoClasses);
            if ($specificity === null) continue;

            foreach ($this->declarations($rule['body']) as $declarationOrder => $declaration) {
                $property = $declaration['property'];
                $value = $declaration['value'];
                $important = $declaration['important'];

                if ($property === 'margin') {
                    $parsed = $this->marginShorthand($value);
                    if ($parsed === null) continue;
                    foreach ($parsed as $side => $sideValue) {
                        $this->consider(
                            $candidates[$side],
                            $sideValue,
                            $important,
                            $specificity,
                            $rule['sourceOrder'],
                            $declarationOrder,
                        );
                    }
                    continue;
                }

                if (preg_match('/^margin-(top|right|bottom|left)$/', $property, $match) !== 1) continue;
                $length = $this->absoluteLength($value);
                if ($length === null) continue;
                $side = $match[1];
                $this->consider(
                    $candidates[$side],
                    max(0.0, $length),
                    $important,
                    $specificity,
                    $rule['sourceOrder'],
                    $declarationOrder,
                );
            }
        }

        $margins = [
            'top' => (float) $candidates['top']['value'],
            'right' => (float) $candidates['right']['value'],
            'bottom' => (float) $candidates['bottom']['value'],
            'left' => (float) $candidates['left']['value'],
        ];
        return $this->clampMargins($margins, $pageWidth, $pageHeight);
    }

    private function consider(array &$current, float $value, bool $important, int $specificity, int $ruleOrder, int $declarationOrder): void
    {
        $wins = $important !== $current['important']
            ? $important
            : ($specificity !== $current['specificity']
                ? $specificity > $current['specificity']
                : ($ruleOrder !== $current['ruleOrder']
                    ? $ruleOrder > $current['ruleOrder']
                    : $declarationOrder >= $current['declarationOrder']));
        if (!$wins) return;
        $current = compact('value', 'important', 'specificity', 'ruleOrder', 'declarationOrder');
    }

    /** @param list<string> $selectors @param list<string> $pseudoClasses */
    private function matchingSpecificity(array $selectors, array $pseudoClasses): ?int
    {
        if ($selectors === []) return 0;
        foreach ($selectors as $selector) {
            if (preg_match('/^:(first|left|right)$/i', trim($selector), $match) !== 1) continue;
            if (in_array(strtolower($match[1]), $pseudoClasses, true)) return 1;
        }
        return null;
    }

    /**
     * @param list<array{selectors:list<string>,body:string,sourceOrder:int}> $rules
     */
    private function collectPageRules(
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
            while ($cursor < $length && ctype_space($css[$cursor])) $cursor++;
            if ($cursor >= $length) break;

            $open = $this->findNextOpenBrace($css, $cursor);
            if ($open === null) break;
            $prelude = trim(substr($css, $cursor, $open - $cursor));
            $close = $this->findMatchingBrace($css, $open);
            if ($close === null) break;
            $body = substr($css, $open + 1, $close - $open - 1);
            $cursor = $close + 1;

            if (preg_match('/^@media\s+(.+)$/is', $prelude, $mediaMatch) === 1) {
                if ($this->mediaQueryEvaluator->matches($mediaMatch[1], $mediaType, $viewportWidth, $viewportHeight)) {
                    $this->collectPageRules($body, $rules, $sourceOrder, $mediaType, $viewportWidth, $viewportHeight);
                }
                continue;
            }

            if (preg_match('/^@page(?:\s+(.+))?$/is', $prelude, $pageMatch) !== 1) continue;
            $selectorText = trim($pageMatch[1] ?? '');
            $selectors = $selectorText === ''
                ? []
                : array_values(array_filter(array_map('trim', explode(',', $selectorText)), static fn(string $value): bool => $value !== ''));
            $rules[] = ['selectors' => $selectors, 'body' => $body, 'sourceOrder' => $sourceOrder++];
        }
    }

    /** @return list<array{property:string,value:string,important:bool}> */
    private function declarations(string $body): array
    {
        $result = [];
        foreach (explode(';', $body) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, ':')) continue;
            [$property, $value] = array_map('trim', explode(':', $chunk, 2));
            if ($property === '' || $value === '') continue;
            $important = preg_match('/!\s*important\s*$/i', $value) === 1;
            if ($important) {
                $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $value));
            }
            if ($value === '') continue;
            $result[] = ['property' => strtolower($property), 'value' => $value, 'important' => $important];
        }
        return $result;
    }

    /** @return array{top:float,right:float,bottom:float,left:float}|null */
    private function marginShorthand(string $value): ?array
    {
        $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 1 || count($tokens) > 4) return null;
        $values = [];
        foreach ($tokens as $token) {
            $length = $this->absoluteLength($token);
            if ($length === null) return null;
            $values[] = max(0.0, $length);
        }
        return match (count($values)) {
            1 => ['top' => $values[0], 'right' => $values[0], 'bottom' => $values[0], 'left' => $values[0]],
            2 => ['top' => $values[0], 'right' => $values[1], 'bottom' => $values[0], 'left' => $values[1]],
            3 => ['top' => $values[0], 'right' => $values[1], 'bottom' => $values[2], 'left' => $values[1]],
            4 => ['top' => $values[0], 'right' => $values[1], 'bottom' => $values[2], 'left' => $values[3]],
        };
    }

    private function absoluteLength(string $value): ?float
    {
        $value = strtolower(trim($value));
        if (preg_match('/^(-?\d+(?:\.\d+)?)(px|pt|in|cm|mm|pc|q)?$/', $value, $match) !== 1) return null;
        $number = (float) $match[1];
        return match ($match[2] ?? 'px') {
            '', 'px' => $number,
            'pt' => Units::ptToPx($number),
            'in' => Units::inToPx($number),
            'cm' => Units::cmToPx($number),
            'mm' => Units::mmToPx($number),
            'pc' => Units::pcToPx($number),
            'q' => Units::qToPx($number),
            default => null,
        };
    }

    /** @param array{top:float,right:float,bottom:float,left:float} $margins */
    private function clampMargins(array $margins, float $width, float $height): array
    {
        $horizontal = $margins['left'] + $margins['right'];
        if ($horizontal > $width) {
            $scale = $width / ($horizontal > 0.0 ? $horizontal : 1.0);
            $margins['left'] *= $scale;
            $margins['right'] *= $scale;
        }
        $vertical = $margins['top'] + $margins['bottom'];
        if ($vertical > $height) {
            $scale = $height / ($vertical > 0.0 ? $vertical : 1.0);
            $margins['top'] *= $scale;
            $margins['bottom'] *= $scale;
        }
        return $margins;
    }

    private function findNextOpenBrace(string $css, int $start): ?int
    {
        $quote = null;
        $escaped = false;
        for ($i = $start, $length = strlen($css); $i < $length; $i++) {
            $ch = $css[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($quote !== null) { if ($ch === $quote) $quote = null; continue; }
            if ($ch === '"' || $ch === "'") { $quote = $ch; continue; }
            if ($ch === '{') return $i;
        }
        return null;
    }

    private function findMatchingBrace(string $css, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        for ($i = $open, $length = strlen($css); $i < $length; $i++) {
            $ch = $css[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($quote !== null) { if ($ch === $quote) $quote = null; continue; }
            if ($ch === '"' || $ch === "'") { $quote = $ch; continue; }
            if ($ch === '{') $depth++;
            elseif ($ch === '}' && --$depth === 0) return $i;
        }
        return null;
    }
}
