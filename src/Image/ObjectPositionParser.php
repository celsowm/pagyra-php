<?php

declare(strict_types=1);

namespace Pagyra\Image;

final class ObjectPositionParser
{
    /** @var array<string,float> */
    private const HORIZONTAL_KEYWORDS = [
        'left' => 0.0,
        'center' => 0.5,
        'right' => 1.0,
    ];

    /** @var array<string,float> */
    private const VERTICAL_KEYWORDS = [
        'top' => 0.0,
        'center' => 0.5,
        'bottom' => 1.0,
    ];

    public static function parse(?string $value): ObjectPosition
    {
        $value = strtolower(trim($value ?? ''));
        if ($value === '') {
            return new ObjectPosition();
        }

        $tokens = preg_split('/\s+/', $value) ?: [];
        if (count($tokens) < 1 || count($tokens) > 2) {
            return new ObjectPosition();
        }

        if (count($tokens) === 1) {
            $token = $tokens[0];
            $percentage = self::percentage($token);
            if ($percentage !== null) {
                return new ObjectPosition($percentage, 0.5);
            }
            if ($token === 'top' || $token === 'bottom') {
                return new ObjectPosition(0.5, self::VERTICAL_KEYWORDS[$token]);
            }
            if (array_key_exists($token, self::HORIZONTAL_KEYWORDS)) {
                return new ObjectPosition(self::HORIZONTAL_KEYWORDS[$token], 0.5);
            }
            return new ObjectPosition();
        }

        [$first, $second] = $tokens;
        $firstPercentage = self::percentage($first);
        $secondPercentage = self::percentage($second);

        if ($firstPercentage !== null && $secondPercentage !== null) {
            return new ObjectPosition($firstPercentage, $secondPercentage);
        }

        $firstHorizontal = self::HORIZONTAL_KEYWORDS[$first] ?? null;
        $firstVertical = self::VERTICAL_KEYWORDS[$first] ?? null;
        $secondHorizontal = self::HORIZONTAL_KEYWORDS[$second] ?? null;
        $secondVertical = self::VERTICAL_KEYWORDS[$second] ?? null;

        if ($firstHorizontal !== null && $secondVertical !== null && !($first === 'center' && $second === 'center')) {
            return new ObjectPosition($firstHorizontal, $secondVertical);
        }
        if ($firstVertical !== null && $secondHorizontal !== null) {
            return new ObjectPosition($secondHorizontal, $firstVertical);
        }
        if ($firstPercentage !== null && $secondVertical !== null) {
            return new ObjectPosition($firstPercentage, $secondVertical);
        }
        if ($firstHorizontal !== null && $secondPercentage !== null) {
            return new ObjectPosition($firstHorizontal, $secondPercentage);
        }
        if ($first === 'center' && $second === 'center') {
            return new ObjectPosition(0.5, 0.5);
        }

        return new ObjectPosition();
    }

    private static function percentage(string $value): ?float
    {
        if (preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+))%$/', trim($value), $match) !== 1) {
            return null;
        }

        $number = (float) $match[1];
        return is_finite($number) ? $number / 100.0 : null;
    }
}
