<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class ObjectPositionParser
{
    public static function parse(?string $value): ObjectPosition
    {
        $value = strtolower(trim($value ?? ''));
        if ($value === '') {
            return new ObjectPosition();
        }

        $parts = preg_split('/\s+/', $value) ?: [];
        if (count($parts) === 1) {
            $part = $parts[0];
            if (in_array($part, ['top', 'bottom'], true)) {
                return new ObjectPosition(0.5, self::axis($part, false));
            }
            return new ObjectPosition(self::axis($part, true), 0.5);
        }

        return new ObjectPosition(
            self::axis($parts[0], true),
            self::axis($parts[1], false),
        );
    }

    private static function axis(string $value, bool $horizontal): float
    {
        return match ($value) {
            'left' => 0.0,
            'right' => 1.0,
            'top' => 0.0,
            'bottom' => 1.0,
            'center' => 0.5,
            default => self::percentage($value) ?? 0.5,
        };
    }

    private static function percentage(string $value): ?float
    {
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', trim($value), $match) !== 1) {
            return null;
        }

        return (float) $match[1] / 100.0;
    }
}
