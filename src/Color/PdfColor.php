<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Color;

final class PdfColor
{
    private const CSS_COLOR_NAMES = [
        'aliceblue' => '#F0F8FF',
        'antiquewhite' => '#FAEBD7',
        'aqua' => '#00FFFF',
        'aquamarine' => '#7FFFD4',
        'azure' => '#F0FFFF',
        'beige' => '#F5F5DC',
        'bisque' => '#FFE4C4',
        'black' => '#000000',
        'blanchedalmond' => '#FFEBCD',
        'blue' => '#0000FF',
        'blueviolet' => '#8A2BE2',
        'brown' => '#A52A2A',
        'burlywood' => '#DEB887',
        'cadetblue' => '#5F9EA0',
        'chartreuse' => '#7FFF00',
        'chocolate' => '#D2691E',
        'coral' => '#FF7F50',
        'cornflowerblue' => '#6495ED',
        'cornsilk' => '#FFF8DC',
        'crimson' => '#DC143C',
        'cyan' => '#00FFFF',
        'darkblue' => '#00008B',
        'darkcyan' => '#008B8B',
        'darkgoldenrod' => '#B8860B',
        'darkgray' => '#A9A9A9',
        'darkgreen' => '#006400',
        'darkgrey' => '#A9A9A9',
        'darkkhaki' => '#BDB76B',
        'darkmagenta' => '#8B008B',
        'darkolivegreen' => '#556B2F',
        'darkorange' => '#FF8C00',
        'darkorchid' => '#9932CC',
        'darkred' => '#8B0000',
        'darksalmon' => '#E9967A',
        'darkseagreen' => '#8FBC8F',
        'darkslateblue' => '#483D8B',
        'darkslategray' => '#2F4F4F',
        'darkslategrey' => '#2F4F4F',
        'darkturquoise' => '#00CED1',
        'darkviolet' => '#9400D3',
        'deeppink' => '#FF1493',
        'deepskyblue' => '#00BFFF',
        'dimgray' => '#696969',
        'dimgrey' => '#696969',
        'dodgerblue' => '#1E90FF',
        'firebrick' => '#B22222',
        'floralwhite' => '#FFFAF0',
        'forestgreen' => '#228B22',
        'fuchsia' => '#FF00FF',
        'gainsboro' => '#DCDCDC',
        'ghostwhite' => '#F8F8FF',
        'gold' => '#FFD700',
        'goldenrod' => '#DAA520',
        'gray' => '#808080',
        'green' => '#008000',
        'greenyellow' => '#ADFF2F',
        'grey' => '#808080',
        'honeydew' => '#F0FFF0',
        'hotpink' => '#FF69B4',
        'indianred' => '#CD5C5C',
        'indigo' => '#4B0082',
        'ivory' => '#FFFFF0',
        'khaki' => '#F0E68C',
        'lavender' => '#E6E6FA',
        'lavenderblush' => '#FFF0F5',
        'lawngreen' => '#7CFC00',
        'lemonchiffon' => '#FFFACD',
        'lightblue' => '#ADD8E6',
        'lightcoral' => '#F08080',
        'lightcyan' => '#E0FFFF',
        'lightgoldenrodyellow' => '#FAFAD2',
        'lightgray' => '#D3D3D3',
        'lightgreen' => '#90EE90',
        'lightgrey' => '#D3D3D3',
        'lightpink' => '#FFB6C1',
        'lightsalmon' => '#FFA07A',
        'lightseagreen' => '#20B2AA',
        'lightskyblue' => '#87CEFA',
        'lightslategray' => '#778899',
        'lightslategrey' => '#778899',
        'lightsteelblue' => '#B0C4DE',
        'lightyellow' => '#FFFFE0',
        'lime' => '#00FF00',
        'limegreen' => '#32CD32',
        'linen' => '#FAF0E6',
        'magenta' => '#FF00FF',
        'maroon' => '#800000',
        'mediumaquamarine' => '#66CDAA',
        'mediumblue' => '#0000CD',
        'mediumorchid' => '#BA55D3',
        'mediumpurple' => '#9370DB',
        'mediumseagreen' => '#3CB371',
        'mediumslateblue' => '#7B68EE',
        'mediumspringgreen' => '#00FA9A',
        'mediumturquoise' => '#48D1CC',
        'mediumvioletred' => '#C71585',
        'midnightblue' => '#191970',
        'mintcream' => '#F5FFFA',
        'mistyrose' => '#FFE4E1',
        'moccasin' => '#FFE4B5',
        'navajowhite' => '#FFDEAD',
        'navy' => '#000080',
        'oldlace' => '#FDF5E6',
        'olive' => '#808000',
        'olivedrab' => '#6B8E23',
        'orange' => '#FFA500',
        'orangered' => '#FF4500',
        'orchid' => '#DA70D6',
        'palegoldenrod' => '#EEE8AA',
        'palegreen' => '#98FB98',
        'paleturquoise' => '#AFEEEE',
        'palevioletred' => '#DB7093',
        'papayawhip' => '#FFEFD5',
        'peachpuff' => '#FFDAB9',
        'peru' => '#CD853F',
        'pink' => '#FFC0CB',
        'plum' => '#DDA0DD',
        'powderblue' => '#B0E0E6',
        'purple' => '#800080',
        'rebeccapurple' => '#663399',
        'red' => '#FF0000',
        'rosybrown' => '#BC8F8F',
        'royalblue' => '#4169E1',
        'saddlebrown' => '#8B4513',
        'salmon' => '#FA8072',
        'sandybrown' => '#F4A460',
        'seagreen' => '#2E8B57',
        'seashell' => '#FFF5EE',
        'sienna' => '#A0522D',
        'silver' => '#C0C0C0',
        'skyblue' => '#87CEEB',
        'slateblue' => '#6A5ACD',
        'slategray' => '#708090',
        'slategrey' => '#708090',
        'snow' => '#FFFAFA',
        'springgreen' => '#00FF7F',
        'steelblue' => '#4682B4',
        'tan' => '#D2B48C',
        'teal' => '#008080',
        'thistle' => '#D8BFD8',
        'tomato' => '#FF6347',
        'turquoise' => '#40E0D0',
        'violet' => '#EE82EE',
        'wheat' => '#F5DEB3',
        'white' => '#FFFFFF',
        'whitesmoke' => '#F5F5F5',
        'yellow' => '#FFFF00',
        'yellowgreen' => '#9ACD32',
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

    
    private function normalizeVector(array $v, int $n): array
    {
        
        $v = array_slice(array_values($v), 0, $n);
        if (count($v) < $n) $v = array_pad($v, $n, 0.0);

        
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
        if (!is_finite($x)) $x = 0.0; 
        return max(0.0, min(1.0, $x));
    }
}