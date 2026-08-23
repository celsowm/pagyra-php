<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Style\ComputedStyle;
use Pagyra\Units\Units;

final class BorderRadiusResolver
{
    public static function resolve(ComputedStyle $style, float $width, float $height, float $fontSize = 16.0): BorderRadius
    {
        $horizontal = ['0', '0', '0', '0'];
        $vertical = ['0', '0', '0', '0'];

        $shorthand = trim($style->get('border-radius', '') ?? '');
        if ($shorthand !== '') {
            [$xRaw, $yRaw] = array_pad(preg_split('/\s*\/\s*/', $shorthand, 2) ?: [], 2, null);
            $horizontal = self::expand($xRaw ?? '0');
            $vertical = self::expand($yRaw ?? $xRaw ?? '0');
        }

        $corners = [
            'border-top-left-radius' => 0,
            'border-top-right-radius' => 1,
            'border-bottom-right-radius' => 2,
            'border-bottom-left-radius' => 3,
        ];
        foreach ($corners as $property => $index) {
            $raw = trim($style->get($property, '') ?? '');
            if ($raw === '') continue;
            $parts = preg_split('/\s+/', $raw) ?: [];
            $horizontal[$index] = $parts[0] ?? '0';
            $vertical[$index] = $parts[1] ?? $parts[0] ?? '0';
        }

        $radius = new BorderRadius(
            new CornerRadius(self::length($horizontal[0], $width, $fontSize), self::length($vertical[0], $height, $fontSize)),
            new CornerRadius(self::length($horizontal[1], $width, $fontSize), self::length($vertical[1], $height, $fontSize)),
            new CornerRadius(self::length($horizontal[2], $width, $fontSize), self::length($vertical[2], $height, $fontSize)),
            new CornerRadius(self::length($horizontal[3], $width, $fontSize), self::length($vertical[3], $height, $fontSize)),
        );

        return self::normalize($radius, $width, $height);
    }

    public static function normalize(BorderRadius $input, float $width, float $height): BorderRadius
    {
        $safeWidth = max(0.0, $width);
        $safeHeight = max(0.0, $height);
        if ($safeWidth <= 0.0 || $safeHeight <= 0.0) return new BorderRadius();

        $f = 1.0;
        foreach ([
            [$input->topLeft->x + $input->topRight->x, $safeWidth],
            [$input->bottomLeft->x + $input->bottomRight->x, $safeWidth],
            [$input->topLeft->y + $input->bottomLeft->y, $safeHeight],
            [$input->topRight->y + $input->bottomRight->y, $safeHeight],
        ] as [$sum, $limit]) {
            if ($sum > 0.0) $f = min($f, $limit / $sum);
        }

        if ($f >= 1.0) return $input;
        return new BorderRadius(
            new CornerRadius($input->topLeft->x * $f, $input->topLeft->y * $f),
            new CornerRadius($input->topRight->x * $f, $input->topRight->y * $f),
            new CornerRadius($input->bottomRight->x * $f, $input->bottomRight->y * $f),
            new CornerRadius($input->bottomLeft->x * $f, $input->bottomLeft->y * $f),
        );
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private static function expand(string $raw): array
    {
        $parts = preg_split('/\s+/', trim($raw)) ?: ['0'];
        return match (count($parts)) {
            1 => [$parts[0], $parts[0], $parts[0], $parts[0]],
            2 => [$parts[0], $parts[1], $parts[0], $parts[1]],
            3 => [$parts[0], $parts[1], $parts[2], $parts[1]],
            default => [$parts[0], $parts[1], $parts[2], $parts[3]],
        };
    }

    private static function length(string $raw, float $percentReference, float $fontSize): float
    {
        $value = strtolower(trim($raw));
        if ($value === '') return 0.0;
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $value, $m) === 1) return max(0.0, (float) $m[1] * $percentReference / 100.0);
        if (preg_match('/^(-?\d+(?:\.\d+)?)px$/', $value, $m) === 1) return max(0.0, (float) $m[1]);
        if (preg_match('/^(-?\d+(?:\.\d+)?)pt$/', $value, $m) === 1) return max(0.0, Units::ptToPx((float) $m[1]));
        if (preg_match('/^(-?\d+(?:\.\d+)?)em$/', $value, $m) === 1) return max(0.0, (float) $m[1] * $fontSize);
        if (preg_match('/^(-?\d+(?:\.\d+)?)rem$/', $value, $m) === 1) return max(0.0, (float) $m[1] * 16.0);
        return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
    }
}
