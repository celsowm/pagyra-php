<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Color;

final class PdfColor
{
    private const CSS_COLOR_NAMES = [
        'aliceblue' => '#f0f8ff',
        'antiquewhite' => '#faebd7',
        'aqua' => '#00ffff',
        'aquamarine' => '#7fffd4',
        'azure' => '#f0ffff',
        'beige' => '#f5f5dc',
        'bisque' => '#ffe4c4',
        'black' => '#000000',
        'blanchedalmond' => '#ffebcd',
        'blue' => '#0000ff',
        'blueviolet' => '#8a2be2',
        'brown' => '#a52a2a',
        'burlywood' => '#deb887',
        'cadetblue' => '#5f9ea0',
        'chartreuse' => '#7fff00',
        'chocolate' => '#d2691e',
        'coral' => '#ff7f50',
        'cornflowerblue' => '#6495ed',
        'cornsilk' => '#fff8dc',
        'crimson' => '#dc143c',
        'cyan' => '#00ffff',
        'darkblue' => '#00008b',
        'darkcyan' => '#008b8b',
        'darkgoldenrod' => '#b8860b',
        'darkgray' => '#a9a9a9',
        'darkgreen' => '#006400',
        'darkgrey' => '#a9a9a9',
        'darkkhaki' => '#bdb76b',
        'darkmagenta' => '#8b008b',
        'darkolivegreen' => '#556b2f',
        'darkorange' => '#ff8c00',
        'darkorchid' => '#9932cc',
        'darkred' => '#8b0000',
        'darksalmon' => '#e9967a',
        'darkseagreen' => '#8fbc8f',
        'darkslateblue' => '#483d8b',
        'darkslategray' => '#2f4f4f',
        'darkslategrey' => '#2f4f4f',
        'darkturquoise' => '#00ced1',
        'darkviolet' => '#9400d3',
        'deeppink' => '#ff1493',
        'deepskyblue' => '#00bfff',
        'dimgray' => '#696969',
        'dimgrey' => '#696969',
        'dodgerblue' => '#1e90ff',
        'firebrick' => '#b22222',
        'floralwhite' => '#fffaf0',
        'forestgreen' => '#228b22',
        'fuchsia' => '#ff00ff',
        'gainsboro' => '#dcdcdc',
        'ghostwhite' => '#f8f8ff',
        'gold' => '#ffd700',
        'goldenrod' => '#daa520',
        'gray' => '#808080',
        'green' => '#008000',
        'greenyellow' => '#adff2f',
        'grey' => '#808080',
        'honeydew' => '#f0fff0',
        'hotpink' => '#ff69b4',
        'indianred' => '#cd5c5c',
        'indigo' => '#4b0082',
        'ivory' => '#fffff0',
        'khaki' => '#f0e68c',
        'lavender' => '#e6e6fa',
        'lavenderblush' => '#fff0f5',
        'lawngreen' => '#7cfc00',
        'lemonchiffon' => '#fffacd',
        'lightblue' => '#add8e6',
        'lightcoral' => '#f08080',
        'lightcyan' => '#e0ffff',
        'lightgoldenrodyellow' => '#fafad2',
        'lightgray' => '#d3d3d3',
        'lightgreen' => '#90ee90',
        'lightgrey' => '#d3d3d3',
        'lightpink' => '#ffb6c1',
        'lightsalmon' => '#ffa07a',
        'lightseagreen' => '#20b2aa',
        'lightskyblue' => '#87cefa',
        'lightslategray' => '#778899',
        'lightslategrey' => '#778899',
        'lightsteelblue' => '#b0c4de',
        'lightyellow' => '#ffffe0',
        'lime' => '#00ff00',
        'limegreen' => '#32cd32',
        'linen' => '#faf0e6',
        'magenta' => '#ff00ff',
        'maroon' => '#800000',
        'mediumaquamarine' => '#66cdaa',
        'mediumblue' => '#0000cd',
        'mediumorchid' => '#ba55d3',
        'mediumpurple' => '#9370db',
        'mediumseagreen' => '#3cb371',
        'mediumslateblue' => '#7b68ee',
        'mediumspringgreen' => '#00fa9a',
        'mediumturquoise' => '#48d1cc',
        'mediumvioletred' => '#c71585',
        'midnightblue' => '#191970',
        'mintcream' => '#f5fffa',
        'mistyrose' => '#ffe4e1',
        'moccasin' => '#ffe4b5',
        'navajowhite' => '#ffdead',
        'navy' => '#000080',
        'oldlace' => '#fdf5e6',
        'olive' => '#808000',
        'olivedrab' => '#6b8e23',
        'orange' => '#ffa500',
        'orangered' => '#ff4500',
        'orchid' => '#da70d6',
        'palegoldenrod' => '#eee8aa',
        'palegreen' => '#98fb98',
        'paleturquoise' => '#afeeee',
        'palevioletred' => '#db7093',
        'papayawhip' => '#ffefd5',
        'peachpuff' => '#ffdab9',
        'peru' => '#cd853f',
        'pink' => '#ffc0cb',
        'plum' => '#dda0dd',
        'powderblue' => '#b0e0e6',
        'purple' => '#800080',
        'rebeccapurple' => '#663399',
        'red' => '#ff0000',
        'rosybrown' => '#bc8f8f',
        'royalblue' => '#4169e1',
        'saddlebrown' => '#8b4513',
        'salmon' => '#fa8072',
        'sandybrown' => '#f4a460',
        'seagreen' => '#2e8b57',
        'seashell' => '#fff5ee',
        'sienna' => '#a0522d',
        'silver' => '#c0c0c0',
        'skyblue' => '#87ceeb',
        'slateblue' => '#6a5acd',
        'slategray' => '#708090',
        'slategrey' => '#708090',
        'snow' => '#fffafa',
        'springgreen' => '#00ff7f',
        'steelblue' => '#4682b4',
        'tan' => '#d2b48c',
        'teal' => '#008080',
        'thistle' => '#d8bfd8',
        'tomato' => '#ff6347',
        'turquoise' => '#40e0d0',
        'violet' => '#ee82ee',
        'wheat' => '#f5deb3',
        'white' => '#ffffff',
        'whitesmoke' => '#f5f5f5',
        'yellow' => '#ffff00',
        'yellowgreen' => '#9acd32',
    ];

    public function getFillOps(string|array|null $color): string
    {
        $spec = (!is_array($color) || !isset($color['space'])) ? $this->normalize($color) : $color;
        if ($spec === null) return '';
        return $this->buildPdfOps($spec, 'fill');
    }

    public function getStrokeOps(string|array|null $color): string
    {
        $spec = (!is_array($color) || !isset($color['space'])) ? $this->normalize($color) : $color;
        if ($spec === null) return '';
        return $this->buildPdfOps($spec, 'stroke');
    }

    /**
     * Normaliza uma cor para o formato interno:
     *   ['space' => 'rgb'|'cmyk'|'gray', 'v' => [...]] // componentes em [0..1]
     *
     * Aceita:
     *   - "#rgb" | "#rrggbb"
     *   - ['space'=>'rgb|cmyk|gray','v'=>[...]]   (clamp aplicado)
     *   - ['rgb'=>[r,g,b]] | ['cmyk'=>[c,m,y,k]] | ['gray'=>x]
     *   - [r,g,b] | [c,m,y,k] | [gray]
     */
    public function normalize(string|array|null $color): ?array
    {
        if ($color === null) {
            return null;
        }

        if (is_string($color)) {
            $trimmed = trim($color);
            if ($trimmed === '') {
                return null;
            }

            $lower = strtolower($trimmed);
            if ($lower === 'transparent') {
                return null;
            }

            if (isset(self::CSS_COLOR_NAMES[$lower])) {
                return $this->normalize(self::CSS_COLOR_NAMES[$lower]);
            }

            if (preg_match('/^rgba?\(([^)]+)\)$/i', $trimmed, $matches) === 1) {
                $parts = array_map('trim', explode(',', $matches[1]));
                if (count($parts) >= 3) {
                    $rgb = [];
                    for ($i = 0; $i < 3; $i++) {
                        $component = $parts[$i] ?? '0';
                        if (substr($component, -1) === '%') {
                            $value = (float)rtrim($component, '%');
                            $value = max(0.0, min(100.0, $value));
                            $rgb[] = ($value / 100.0) * 255.0;
                        } else {
                            $rgb[] = (float)$component;
                        }
                    }
                    $alpha = count($parts) > 3 ? (float)$parts[3] : 1.0;
                    $alpha = max(0.0, min(1.0, $alpha));
                    if ($alpha === 0.0) {
                        return null;
                    }
                    $values = $this->normalizeVector($rgb, 3);
                    if ($alpha < 1.0) {
                        $values = array_map(
                            static function (float $component) use ($alpha): float {
                                return ($alpha * $component) + ((1.0 - $alpha) * 1.0);
                            },
                            $values
                        );
                    }
                    return ['space' => 'rgb', 'v' => $values];
                }
            }

            $hex = ltrim($trimmed, '#');
            if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
                $r = hexdec($hex[0] . $hex[0]);
                $g = hexdec($hex[1] . $hex[1]);
                $b = hexdec($hex[2] . $hex[2]);
                return ['space' => 'rgb', 'v' => [$r / 255, $g / 255, $b / 255]];
            }
            if (preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                return ['space' => 'rgb', 'v' => [$r / 255, $g / 255, $b / 255]];
            }
            return null;
        }

        if (is_array($color)) {
            if (isset($color['space'], $color['v']) && is_array($color['v'])) {
                $space = strtolower((string)$color['space']);
                return match ($space) {
                    'rgb'  => ['space' => 'rgb',  'v' => $this->normalizeVector($color['v'], 3)],
                    'cmyk' => ['space' => 'cmyk', 'v' => $this->normalizeVector($color['v'], 4)],
                    'gray', 'grey' => ['space' => 'gray', 'v' => [$this->normalizeComponent((float)($color['v'][0] ?? 0))]],
                    default => null,
                };
            }

            if (isset($color['rgb']) && is_array($color['rgb'])) {
                return ['space' => 'rgb', 'v' => $this->normalizeVector($color['rgb'], 3)];
            }
            if (isset($color['cmyk']) && is_array($color['cmyk'])) {
                return ['space' => 'cmyk', 'v' => $this->normalizeVector($color['cmyk'], 4)];
            }
            if (array_key_exists('gray', $color) || array_key_exists('grey', $color)) {
                $gval = array_key_exists('gray', $color) ? $color['gray'] : $color['grey'];
                $g = is_array($gval) ? ($gval[0] ?? null) : $gval;
                return is_numeric($g) ? ['space' => 'gray', 'v' => [$this->normalizeComponent((float)$g)]] : null;
            }

            if (array_is_list($color)) {
                $n = count($color);
                if ($n === 3) return ['space' => 'rgb',  'v' => $this->normalizeVector($color, 3)];
                if ($n === 4) return ['space' => 'cmyk', 'v' => $this->normalizeVector($color, 4)];
                if ($n === 1) return ['space' => 'gray', 'v' => [$this->normalizeComponent((float)$color[0])]];
            }
        }

        return null;
    }

    /**
     * Converte vetor para floats em [0..1], aceitando 0..255.
     * Ex.: [79,140,255] => [0.310, 0.549, 1.000]
     */
    private function normalizeVector(array $v, int $n): array
    {
        // Preenche faltantes com 0 e descarta extras
        $v = array_slice(array_values($v), 0, $n);
        if (count($v) < $n) $v = array_pad($v, $n, 0.0);

        // Detecta escala 0..255
        $hasOver1 = false;
        for ($i = 0; $i < $n; $i++) {
            if ((float)$v[$i] > 1.0) {
                $hasOver1 = true;
                break;
            }
        }

        for ($i = 0; $i < $n; $i++) {
            $vx = (float)$v[$i];
            $vx = $hasOver1 ? ($vx / 255.0) : $vx;
            $v[$i] = $this->normalizeComponent($vx);
        }

        return $v;
    }

    private function buildPdfOps(array $spec, string $type): string
    {
        $space = $spec['space'] ?? null;
        $v = $spec['v'] ?? [];

        $operator = match ($type) {
            'fill'   => match ($space) {
                'rgb' => 'rg',
                'gray' => 'g',
                'cmyk' => 'k',
                default => null
            },
            'stroke' => match ($space) {
                'rgb' => 'RG',
                'gray' => 'G',
                'cmyk' => 'K',
                default => null
            },
            default  => null,
        };

        if ($operator === null || empty($v)) {
            return '';
        }

        $values = implode(' ', array_map(static fn($c) => sprintf('%.3F', (float)$c), $v));
        return "{$values} {$operator}\n";
    }

    private function normalizeComponent(float $x): float
    {
        if (!is_finite($x)) $x = 0.0; // PHP 8.2 tem is_finite()
        return max(0.0, min(1.0, $x));
    }
}
