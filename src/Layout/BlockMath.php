<?php

declare(strict_types=1);

namespace Pagyra\Layout;

final class BlockMath
{
    /** @param list<float> $margins */
    public static function collapseMarginSet(array $margins): float
    {
        $positives = array_values(array_filter($margins, static fn(float $m): bool => $m > 0));
        $negatives = array_values(array_filter($margins, static fn(float $m): bool => $m < 0));

        if ($negatives === []) {
            return $positives === [] ? 0.0 : max($positives);
        }
        if ($positives === []) {
            return min($negatives);
        }

        return max($positives) + min($negatives);
    }

    /** @return array{left:float,right:float} */
    public static function resolveAutoMargins(
        float $containingWidth,
        float $borderBoxWidth,
        float $resolvedLeft,
        float $resolvedRight,
        bool $leftAuto,
        bool $rightAuto,
    ): array {
        $left = $resolvedLeft;
        $right = $resolvedRight;
        $remaining = $containingWidth - ($borderBoxWidth + $resolvedLeft + $resolvedRight);

        if (!is_finite($remaining)) {
            return ['left' => $left, 'right' => $right];
        }

        if ($remaining < 0) {
            if ($leftAuto && $rightAuto) {
                return ['left' => 0.0, 'right' => 0.0];
            }
            if ($leftAuto) {
                return ['left' => 0.0, 'right' => $right];
            }
            if ($rightAuto) {
                return ['left' => $left, 'right' => 0.0];
            }
            return ['left' => $left, 'right' => $right + $remaining];
        }

        if ($leftAuto && $rightAuto) {
            return ['left' => $remaining / 2, 'right' => $remaining / 2];
        }
        if ($leftAuto) {
            return ['left' => $remaining, 'right' => $right];
        }
        if ($rightAuto) {
            return ['left' => $left, 'right' => $remaining];
        }

        return ['left' => $left, 'right' => $right + $remaining];
    }
}
