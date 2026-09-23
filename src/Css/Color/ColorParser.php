<?php

declare(strict_types=1);

namespace Pagyra\Css\Color;

/**
 * CSS Color 4 parsing for the forms documents actually use: the full named-colour table, hex
 * (3, 4, 6 and 8 digits), `rgb()`/`rgba()` and `hsl()`/`hsla()` in both the legacy comma syntax
 * and the space syntax with a slash alpha (`rgb(0 0 255 / 50%)`), numbers or percentages, hue in
 * deg/rad/grad/turn, and `hwb()`.
 *
 * The table used to hold only the 16 CSS 2 names plus orange and rebeccapurple, and there was no
 * `hsl()` at all, so `color: darkred` or the CKEditor palette's `hsl(0, 75%, 60%)` came out black
 * and `background: hsla(...)` painted nothing. The names are ported from the reference
 * (pagyra-js `src/css/named-colors.ts`).
 */
final class ColorParser
{
    private const NAMED = [
        'aliceblue' => '#f0f8ff', 'antiquewhite' => '#faebd7', 'aqua' => '#00ffff', 'aquamarine' => '#7fffd4',
        'azure' => '#f0ffff', 'beige' => '#f5f5dc', 'bisque' => '#ffe4c4', 'black' => '#000000',
        'blanchedalmond' => '#ffebcd', 'blue' => '#0000ff', 'blueviolet' => '#8a2be2', 'brown' => '#a52a2a',
        'burlywood' => '#deb887', 'cadetblue' => '#5f9ea0', 'chartreuse' => '#7fff00', 'chocolate' => '#d2691e',
        'coral' => '#ff7f50', 'cornflowerblue' => '#6495ed', 'cornsilk' => '#fff8dc', 'crimson' => '#dc143c',
        'cyan' => '#00ffff', 'darkblue' => '#00008b', 'darkcyan' => '#008b8b', 'darkgoldenrod' => '#b8860b',
        'darkgray' => '#a9a9a9', 'darkgreen' => '#006400', 'darkgrey' => '#a9a9a9', 'darkkhaki' => '#bdb76b',
        'darkmagenta' => '#8b008b', 'darkolivegreen' => '#556b2f', 'darkorange' => '#ff8c00', 'darkorchid' => '#9932cc',
        'darkred' => '#8b0000', 'darksalmon' => '#e9967a', 'darkseagreen' => '#8fbc8f', 'darkslateblue' => '#483d8b',
        'darkslategray' => '#2f4f4f', 'darkslategrey' => '#2f4f4f', 'darkturquoise' => '#00ced1', 'darkviolet' => '#9400d3',
        'deeppink' => '#ff1493', 'deepskyblue' => '#00bfff', 'dimgray' => '#696969', 'dimgrey' => '#696969',
        'dodgerblue' => '#1e90ff', 'firebrick' => '#b22222', 'floralwhite' => '#fffaf0', 'forestgreen' => '#228b22',
        'fuchsia' => '#ff00ff', 'gainsboro' => '#dcdcdc', 'ghostwhite' => '#f8f8ff', 'gold' => '#ffd700',
        'goldenrod' => '#daa520', 'gray' => '#808080', 'green' => '#008000', 'greenyellow' => '#adff2f',
        'grey' => '#808080', 'honeydew' => '#f0fff0', 'hotpink' => '#ff69b4', 'indianred' => '#cd5c5c',
        'indigo' => '#4b0082', 'ivory' => '#fffff0', 'khaki' => '#f0e68c', 'lavender' => '#e6e6fa',
        'lavenderblush' => '#fff0f5', 'lawngreen' => '#7cfc00', 'lemonchiffon' => '#fffacd', 'lightblue' => '#add8e6',
        'lightcoral' => '#f08080', 'lightcyan' => '#e0ffff', 'lightgoldenrodyellow' => '#fafad2', 'lightgray' => '#d3d3d3',
        'lightgreen' => '#90ee90', 'lightgrey' => '#d3d3d3', 'lightpink' => '#ffb6c1', 'lightsalmon' => '#ffa07a',
        'lightseagreen' => '#20b2aa', 'lightskyblue' => '#87cefa', 'lightslategray' => '#778899', 'lightslategrey' => '#778899',
        'lightsteelblue' => '#b0c4de', 'lightyellow' => '#ffffe0', 'lime' => '#00ff00', 'limegreen' => '#32cd32',
        'linen' => '#faf0e6', 'magenta' => '#ff00ff', 'maroon' => '#800000', 'mediumaquamarine' => '#66cdaa',
        'mediumblue' => '#0000cd', 'mediumorchid' => '#ba55d3', 'mediumpurple' => '#9370db', 'mediumseagreen' => '#3cb371',
        'mediumslateblue' => '#7b68ee', 'mediumspringgreen' => '#00fa9a', 'mediumturquoise' => '#48d1cc', 'mediumvioletred' => '#c71585',
        'midnightblue' => '#191970', 'mintcream' => '#f5fffa', 'mistyrose' => '#ffe4e1', 'moccasin' => '#ffe4b5',
        'navajowhite' => '#ffdead', 'navy' => '#000080', 'oldlace' => '#fdf5e6', 'olive' => '#808000',
        'olivedrab' => '#6b8e23', 'orange' => '#ffa500', 'orangered' => '#ff4500', 'orchid' => '#da70d6',
        'palegoldenrod' => '#eee8aa', 'palegreen' => '#98fb98', 'paleturquoise' => '#afeeee', 'palevioletred' => '#db7093',
        'papayawhip' => '#ffefd5', 'peachpuff' => '#ffdab9', 'peru' => '#cd853f', 'pink' => '#ffc0cb',
        'plum' => '#dda0dd', 'powderblue' => '#b0e0e6', 'purple' => '#800080', 'rebeccapurple' => '#663399',
        'red' => '#ff0000', 'rosybrown' => '#bc8f8f', 'royalblue' => '#4169e1', 'saddlebrown' => '#8b4513',
        'salmon' => '#fa8072', 'sandybrown' => '#f4a460', 'seagreen' => '#2e8b57', 'seashell' => '#fff5ee',
        'sienna' => '#a0522d', 'silver' => '#c0c0c0', 'skyblue' => '#87ceeb', 'slateblue' => '#6a5acd',
        'slategray' => '#708090', 'slategrey' => '#708090', 'snow' => '#fffafa', 'springgreen' => '#00ff7f',
        'steelblue' => '#4682b4', 'tan' => '#d2b48c', 'teal' => '#008080', 'thistle' => '#d8bfd8',
        'tomato' => '#ff6347', 'turquoise' => '#40e0d0', 'violet' => '#ee82ee', 'wheat' => '#f5deb3',
        'white' => '#ffffff', 'whitesmoke' => '#f5f5f5', 'yellow' => '#ffff00', 'yellowgreen' => '#9acd32',
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

        if (preg_match('/^(rgba?|hsla?|hwb)\(\s*(.*?)\s*\)$/', $normalized, $m) !== 1) {
            return null;
        }
        $arguments = self::arguments($m[2]);
        if ($arguments === null) {
            return null;
        }
        [$channels, $alphaToken] = $arguments;
        $alpha = $alphaToken === null ? 1.0 : self::alpha($alphaToken);
        if ($alpha === null) {
            return null;
        }

        if ($m[1] === 'rgb' || $m[1] === 'rgba') {
            $rgb = [];
            foreach ($channels as $token) {
                $channel = self::rgbChannel($token);
                if ($channel === null) {
                    return null;
                }
                $rgb[] = $channel;
            }

            return new Rgba($rgb[0], $rgb[1], $rgb[2], $alpha);
        }

        $hue = self::hue($channels[0]);
        $second = self::percentage($channels[1]);
        $third = self::percentage($channels[2]);
        if ($hue === null || $second === null || $third === null) {
            return null;
        }
        [$r, $g, $b] = $m[1] === 'hwb' ? self::hwbToRgb($hue, $second, $third) : self::hslToRgb($hue, $second, $third);

        return new Rgba($r * 255.0, $g * 255.0, $b * 255.0, $alpha);
    }

    /**
     * The three channels and the optional alpha, from either `a, b, c[, alpha]` or
     * `a b c[ / alpha]`.
     *
     * @return array{0:list<string>,1:?string}|null
     */
    private static function arguments(string $inner): ?array
    {
        if (str_contains($inner, ',')) {
            $parts = array_map('trim', explode(',', $inner));
            if (count($parts) !== 3 && count($parts) !== 4) {
                return null;
            }

            return [array_slice($parts, 0, 3), $parts[3] ?? null];
        }
        $alpha = null;
        if (str_contains($inner, '/')) {
            [$inner, $alpha] = array_map('trim', explode('/', $inner, 2));
        }
        $parts = preg_split('/\s+/', trim($inner)) ?: [];
        if (count($parts) !== 3) {
            return null;
        }

        return [$parts, $alpha];
    }

    private static function rgbChannel(string $token): ?float
    {
        if ($token === 'none') {
            return 0.0;
        }
        if (preg_match('/^(-?\d*\.?\d+(?:e[+-]?\d+)?)(%)?$/', $token, $m) !== 1) {
            return null;
        }
        $value = (float) $m[1];

        return self::clampColor(isset($m[2]) ? $value * 2.55 : $value);
    }

    private static function alpha(string $token): ?float
    {
        if ($token === 'none') {
            return 0.0;
        }
        if (preg_match('/^(-?\d*\.?\d+(?:e[+-]?\d+)?)(%)?$/', $token, $m) !== 1) {
            return null;
        }

        return self::clampAlpha(isset($m[2]) ? (float) $m[1] / 100.0 : (float) $m[1]);
    }

    /** Hue in degrees, from a bare number or deg/rad/grad/turn. */
    private static function hue(string $token): ?float
    {
        if ($token === 'none') {
            return 0.0;
        }
        if (preg_match('/^(-?\d*\.?\d+(?:e[+-]?\d+)?)(deg|rad|grad|turn)?$/', $token, $m) !== 1) {
            return null;
        }
        $value = (float) $m[1];
        $degrees = match ($m[2] ?? 'deg') {
            'rad' => rad2deg($value),
            'grad' => $value * 0.9,
            'turn' => $value * 360.0,
            default => $value,
        };

        return fmod(fmod($degrees, 360.0) + 360.0, 360.0);
    }

    /** A percentage (or, as CSS Color 4 allows in the space syntax, a bare number) as 0..1. */
    private static function percentage(string $token): ?float
    {
        if ($token === 'none') {
            return 0.0;
        }
        if (preg_match('/^(-?\d*\.?\d+(?:e[+-]?\d+)?)%?$/', $token, $m) !== 1) {
            return null;
        }

        return max(0.0, min(1.0, (float) $m[1] / 100.0));
    }

    /** CSS Color 4 §7.1 hslToRgb. @return array{0:float,1:float,2:float} */
    private static function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        $f = static function (int $n) use ($hue, $saturation, $lightness): float {
            $k = fmod($n + $hue / 30.0, 12.0);
            $a = $saturation * min($lightness, 1.0 - $lightness);

            return $lightness - $a * max(-1.0, min($k - 3.0, 9.0 - $k, 1.0));
        };

        return [$f(0), $f(8), $f(4)];
    }

    /** CSS Color 4 §8.1 hwbToRgb. @return array{0:float,1:float,2:float} */
    private static function hwbToRgb(float $hue, float $white, float $black): array
    {
        if ($white + $black >= 1.0) {
            $gray = $white / ($white + $black);

            return [$gray, $gray, $gray];
        }
        $rgb = self::hslToRgb($hue, 1.0, 0.5);

        return array_map(static fn(float $c): float => $c * (1.0 - $white - $black) + $white, $rgb);
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
