<?php

declare(strict_types=1);

namespace Pagyra\Css;

use Pagyra\Units\Units;

final class MediaQueryEvaluator
{
    public function matches(
        string $queryList,
        string $mediaType = 'print',
        ?float $viewportWidth = null,
        ?float $viewportHeight = null,
    ): bool {
        foreach ($this->splitQueryList($queryList) as $query) {
            if ($this->matchesSingle($query, $mediaType, $viewportWidth, $viewportHeight)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function splitQueryList(string $value): array
    {
        $queries = [];
        $current = '';
        $depth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $ch = $value[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($ch === ',' && $depth === 0) {
                if (trim($current) !== '') {
                    $queries[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }

        if (trim($current) !== '') {
            $queries[] = trim($current);
        }
        return $queries;
    }

    private function matchesSingle(
        string $query,
        string $mediaType,
        ?float $viewportWidth,
        ?float $viewportHeight,
    ): bool {
        $normalized = strtolower(trim($query));
        $negated = false;

        if (str_starts_with($normalized, 'not ')) {
            $negated = true;
            $normalized = trim(substr($normalized, 4));
        } elseif (str_starts_with($normalized, 'only ')) {
            $normalized = trim(substr($normalized, 5));
        }

        $queryMediaType = 'all';
        if (preg_match('/^(all|print|screen)\b/', $normalized, $match) === 1) {
            $queryMediaType = $match[1];
            $normalized = trim(substr($normalized, strlen($match[0])));
        }

        if (str_starts_with($normalized, 'and ')) {
            $normalized = trim(substr($normalized, 4));
        }

        $typeMatches = $queryMediaType === 'all' || $queryMediaType === strtolower($mediaType);
        $featuresMatch = true;
        if ($normalized !== '') {
            foreach (preg_split('/\s+and\s+/i', $normalized) ?: [] as $feature) {
                if (!$this->matchesFeature($feature, $viewportWidth, $viewportHeight)) {
                    $featuresMatch = false;
                    break;
                }
            }
        }

        $matches = $typeMatches && $featuresMatch;
        return $negated ? !$matches : $matches;
    }

    private function matchesFeature(string $feature, ?float $viewportWidth, ?float $viewportHeight): bool
    {
        $normalized = strtolower(trim($feature));
        if (preg_match('/^\(\s*orientation\s*:\s*(portrait|landscape)\s*\)$/', $normalized, $match) === 1) {
            if ($viewportWidth === null || $viewportHeight === null) {
                return false;
            }
            $orientation = $viewportWidth > $viewportHeight ? 'landscape' : 'portrait';
            return $orientation === $match[1];
        }

        if (preg_match('/^\(\s*(min-|max-)?(width|height)\s*:\s*([^)]+)\)$/', $normalized, $match) !== 1) {
            return false;
        }

        $dimension = $match[2] === 'width' ? $viewportWidth : $viewportHeight;
        $expected = $this->lengthToPx($match[3]);
        if ($dimension === null || $expected === null) {
            return false;
        }

        return match ($match[1] ?? '') {
            'min-' => $dimension >= $expected,
            'max-' => $dimension <= $expected,
            default => abs($dimension - $expected) < 0.01,
        };
    }

    private function lengthToPx(string $value): ?float
    {
        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+))(px|pt|in|cm|mm|q)?$/i', trim($value), $match) !== 1) {
            return null;
        }

        $number = (float) $match[1];
        if (!is_finite($number)) {
            return null;
        }
        $unit = strtolower($match[2] ?? ($number == 0.0 ? 'px' : ''));

        return match ($unit) {
            'px' => $number,
            'pt' => Units::ptToPx($number),
            'in' => Units::inToPx($number),
            'cm' => Units::cmToPx($number),
            'mm' => Units::mmToPx($number),
            'q' => Units::qToPx($number),
            default => null,
        };
    }
}
