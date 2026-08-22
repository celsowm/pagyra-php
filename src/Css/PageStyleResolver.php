<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Units\Units;

final class PageStyleResolver
{
    /**
     * @param array{top:float,right:float,bottom:float,left:float} $fallbackMargins
     * @return array{width:float,height:float,margins:array{top:float,right:float,bottom:float,left:float}}
     */
    public function resolve(string $css, float $fallbackWidth, float $fallbackHeight, array $fallbackMargins): array
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $sizeCandidate = null;
        $margins = [
            'top' => ['value' => (float) $fallbackMargins['top'], 'important' => false, 'order' => -1],
            'right' => ['value' => (float) $fallbackMargins['right'], 'important' => false, 'order' => -1],
            'bottom' => ['value' => (float) $fallbackMargins['bottom'], 'important' => false, 'order' => -1],
            'left' => ['value' => (float) $fallbackMargins['left'], 'important' => false, 'order' => -1],
        ];

        $ruleOrder = 0;
        if (preg_match_all('/@page\s*\{([^{}]*)\}/is', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $declarationOrder = 0;
                foreach ($this->declarations($match[1]) as $declaration) {
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
                $ruleOrder++;
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

        return [
            'width' => $width,
            'height' => $height,
            'margins' => [
                'top' => $margins['top']['value'],
                'right' => $margins['right']['value'],
                'bottom' => $margins['bottom']['value'],
                'left' => $margins['left']['value'],
            ],
        ];
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
