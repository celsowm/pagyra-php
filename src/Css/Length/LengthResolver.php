<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

final class LengthResolver
{
    public static function resolveRelative(RelativeLength $value, float $fontSize, float $rootFontSize): float
    {
        return $value->unit === 'em' ? $value->value * $fontSize : $value->value * $rootFontSize;
    }

    public static function resolve(
        float|RelativeLength|PercentLength|CalcLength|string|null $value,
        float $reference,
        float $fontSize = 16.0,
        float $rootFontSize = 16.0,
        ?float $containerWidth = null,
        ?float $containerHeight = null,
        string|float $auto = 'reference',
    ): float {
        if ($value === null) return 0.0;
        if (is_float($value) || is_int($value)) return (float) $value;
        if ($value instanceof RelativeLength) return self::resolveRelative($value, $fontSize, $rootFontSize);
        if ($value instanceof PercentLength) return $value->ratio * $reference;
        if ($value instanceof CalcLength) {
            $resolved = $value->withResolvedFonts($fontSize, $rootFontSize);
            return $resolved->resolve($reference, $containerWidth ?? $reference, $containerHeight ?? $reference);
        }
        if ($value === 'auto') {
            if ($auto === 'reference') return $reference;
            if ($auto === 'zero') return 0.0;
            return (float) $auto;
        }
        return 0.0;
    }
}
