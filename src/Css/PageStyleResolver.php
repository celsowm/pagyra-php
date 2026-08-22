<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Units\Units;

final class PageStyleResolver
{
    public function __construct(
        private readonly MediaQueryEvaluator $mediaQueryEvaluator = new MediaQueryEvaluator(),
    ) {
    }

    /**
     * @param array{top:float,right:float,bottom:float,left:float} $fallbackMargins
     * @return array{width:float,height:float,margins:array{top:float,right:float,bottom:float,left:float}}
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
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $sizeCandidate = null;
        $margins = [
            'top' => ['value' => (float) $fallbackMargins['top'], 'important' => false, 'order' => -1],
            'right' => ['value' => (float) $fallbackMargins['right'], 'important' => false, 'order' => -1],
            'bottom' => ['value' => (float) $fallbackMargins['bottom'], 'important' => false, 'order' => -1],
            'left' => ['value' => (float) $fallbackMargins['left'], 'important' => false, 'order' => -1],
        ];

        foreach ($this->activePageBodies($css, $mediaType, $viewportWidth, $viewportHeight) as $ruleOrder => $body) {
            $declarationOrder = 0;
            foreach ($this->declarations($body) as $declaration) {
                $order = $ruleOrder * 10000 + $declarationOrder++;
                $property = $declaration['property'];
                $value = $declaration['value'];
                $important = $declaration['important'];

                if ($property === 'size') {
                    if ($this->wins($important, $order, $sizeCandidate)) {
                        $sizeCandidate = ['value' => $value, 'important' => $important, 'order' => $order];
                    }
                    continue;
                }

                if ($property === 'margin') {
                    $resolved = $this->marginShorthand($value);
                    if ($resolved === null) continue;
                    foreach ($resolved as $side => $sideValue) {
                        if ($this->wins($important, $order, $margins[$side])) {
                            $margins[$side] = ['value' => $sideValue, 'important' => $important, 'order' => $order];
                        }
                    }
                    continue;
                }

                if (preg_match('/^margin-(top|right|bottom|left)$/', $property, $sideMatch) === 1) {
                    $length = $this->absoluteLength($value);
                    if ($length === null) continue;
                    $side = $sideMatch[1];
                    if ($this->wins($important, $order, $margins[$side])) {
                        $margins[$side] = ['value' => max(0.0, $length), 'important' => $important, 'order' => $order];
                    }
                }
            }
        }

        $width = $fallbackWidth;
        $height = $fallbackHeight;
        if ($sizeCandidate !== null) {
            $size = $this->pageSize($sizeCandidate['value'], $fallbackWidth, $fallbackHeight);
            if ($size !== null) {
                [$width, $height] = $size;
            }
        }

        $resolvedMargins = [
            'top' => (float) $margins['top']['value'],
            'right' => (float) $margins['right']['value'],
            'bottom' => (float) $margins['bottom']['value'],
            'left' => (float) $margins['left']['value'],
        ];
        $resolvedMargins = $this->clampMarginsToPage($resolvedMargins, $width, $height);

        return [
            'width' => $width,
            'height' => $height,
            'margins' => $resolvedMargins,
        ];
    }

    /**
     * @param array{top:float,right:float,bottom:float,left:float} $margins
     * @return array{top:float,right:float,bottom:float,left:float}
     */
    private function clampMarginsToPage(array $margins, float $width, float $height): array
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

    /** @return list<string> */
    private function activePageBodies(
        string $css,
        string $mediaType,
        ?float $viewportWidth,
        ?float $viewportHeight,
    ): array {
        $bodies = [];
        $this->collectActivePageBodies($css, $bodies, $mediaType, $viewportWidth, $viewportHeight);
        return $bodies;
    }

    /** @param list<string> $bodies */
    private function collectActivePageBodies(
        string $css,
        array &$bodies,
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
                    $this->collectActivePageBodies($body, $bodies, $mediaType, $viewportWidth, $viewportHeight);
                }
                continue;
            }

            if (strcasecmp($prelude, '@page') === 0) {
                $bodies[] = $body;
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
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($quote !== null) {
                if ($ch === $quote) $quote = null;
                continue;
            }
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
        $length = strlen($css);
        for ($i = $open; $i < $length; $i++) {
            $ch = $css[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($quote !== null) {
                if ($ch === $quote) $quote = null;
                continue;
            }
            if ($ch === '"' || $ch === "'") { $quote = $ch; continue; }
            if ($ch === '{') $depth++;
            elseif ($ch === '}' && --$depth === 0) return $i;
        }
        return null;
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
            $result[] = [
                'property' => strtolower($property),
                'value' => $value,
                'important' => $important,
            ];
        }
        return $result;
    }

    private function wins(bool $important, int $order, ?array $current): bool
    {
        if ($current === null) return true;
        if ($important !== $current['important']) return $important;
        return $order >= $current['order'];
    }

    /** @return array{0:float,1:float}|null */
    private function pageSize(string $value, float $fallbackWidth, float $fallbackHeight): ?array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === [] || in_array('auto', $tokens, true)) return null;

        $orientation = null;
        foreach (['portrait', 'landscape'] as $candidate) {
            $index = array_search($candidate, $tokens, true);
            if ($index !== false) {
                $orientation = $candidate;
                array_splice($tokens, (int) $index, 1);
                break;
            }
        }

        $named = [
            'a5' => [Units::mmToPx(148), Units::mmToPx(210)],
            'a4' => [Units::mmToPx(210), Units::mmToPx(297)],
            'a3' => [Units::mmToPx(297), Units::mmToPx(420)],
            'b5' => [Units::mmToPx(176), Units::mmToPx(250)],
            'b4' => [Units::mmToPx(250), Units::mmToPx(353)],
            'jis-b5' => [Units::mmToPx(182), Units::mmToPx(257)],
            'jis-b4' => [Units::mmToPx(257), Units::mmToPx(364)],
            'letter' => [Units::inToPx(8.5), Units::inToPx(11)],
            'legal' => [Units::inToPx(8.5), Units::inToPx(14)],
            'ledger' => [Units::inToPx(17), Units::inToPx(11)],
            'tabloid' => [Units::inToPx(11), Units::inToPx(17)],
        ];

        if ($tokens === []) {
            $size = [$fallbackWidth, $fallbackHeight];
        } elseif (count($tokens) === 1 && isset($named[$tokens[0]])) {
            $size = $named[$tokens[0]];
        } elseif (count($tokens) === 1) {
            $side = $this->absoluteLength($tokens[0]);
            if ($side === null || $side <= 0.0) return null;
            $size = [$side, $side];
        } elseif (count($tokens) === 2) {
            $width = $this->absoluteLength($tokens[0]);
            $height = $this->absoluteLength($tokens[1]);
            if ($width === null || $height === null || $width <= 0.0 || $height <= 0.0) return null;
            $size = [$width, $height];
        } else {
            return null;
        }

        if ($orientation === 'landscape' && $size[0] < $size[1]) {
            $size = [$size[1], $size[0]];
        } elseif ($orientation === 'portrait' && $size[0] > $size[1]) {
            $size = [$size[1], $size[0]];
        }

        return [(float) $size[0], (float) $size[1]];
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
}
