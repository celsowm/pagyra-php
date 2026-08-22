<?php

declare(strict_types=1);

namespace Pagyra\Css\Color;

final class ColorParser
{
    private const NAMED = [
        'black' => '#000000', 'silver' => '#c0c0c0', 'gray' => '#808080', 'white' => '#ffffff',
        'maroon' => '#800000', 'red' => '#ff0000', 'purple' => '#800080', 'fuchsia' => '#ff00ff',
        'green' => '#008000', 'lime' => '#00ff00', 'olive' => '#808000', 'yellow' => '#ffff00',
        'navy' => '#000080', 'blue' => '#0000ff', 'teal' => '#008080', 'aqua' => '#00ffff',
        'orange' => '#ffa500', 'rebeccapurple' => '#663399',
    ];

    public static function parse(?string $value): ?Rgba
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = strtolower(trim($value));
        $normalized = self::NAMED[$normalized] ?? $normalized;
        if ($normalized === 'transparent') {
            return null;
        }

        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $normalized, $m)) {
            $d = $m[1];
            if (strlen($d) <= 4) {
                $d = implode('', array_map(static fn(string $c): string => $c . $c, str_split($d)));
            }
            $r = hexdec(substr($d, 0, 2));
            $g = hexdec(substr($d, 2, 2));
            $b = hexdec(substr($d, 4, 2));
            $a = strlen($d) === 8 ? hexdec(substr($d, 6, 2)) / 255 : 1.0;
            return new Rgba($r, $g, $b, $a);
        }

        if (preg_match('/^rgba?\((.+)\)$/', $normalized, $m)) {
            $parts = array_map('trim', explode(',', $m[1]));
            if (count($parts) < 3) {
                return null;
            }
            return new Rgba(
                self::clampColor((float) $parts[0]),
                self::clampColor((float) $parts[1]),
                self::clampColor((float) $parts[2]),
                isset($parts[3]) ? self::clampAlpha((float) $parts[3]) : 1.0,
            );
        }

        return null;
    }

    private static function clampColor(float $value): float
    {
        return max(0.0, min(255.0, $value));
    }

    private static function clampAlpha(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
