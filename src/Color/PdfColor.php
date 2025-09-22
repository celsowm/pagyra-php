<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Color;

final class PdfColor
{
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

        // String hex: #rgb ou #rrggbb
        if (is_string($color)) {
            $s = ltrim(trim($color), '#'); // só remove '#'
            if (preg_match('/^[0-9a-fA-F]{3}$/', $s)) {
                $r = hexdec($s[0] . $s[0]);
                $g = hexdec($s[1] . $s[1]);
                $b = hexdec($s[2] . $s[2]);
                return ['space' => 'rgb', 'v' => [$r / 255, $g / 255, $b / 255]];
            }
            if (preg_match('/^[0-9a-fA-F]{6}$/', $s)) {
                $r = hexdec(substr($s, 0, 2));
                $g = hexdec(substr($s, 2, 2));
                $b = hexdec(substr($s, 4, 2));
                return ['space' => 'rgb', 'v' => [$r / 255, $g / 255, $b / 255]];
            }
            return null;
        }

        // Array
        if (is_array($color)) {
            // Forma já normalizada (garantir clamp)
            if (isset($color['space'], $color['v']) && is_array($color['v'])) {
                $space = strtolower((string)$color['space']);
                return match ($space) {
                    'rgb'  => ['space' => 'rgb',  'v' => $this->normalizeVector($color['v'], 3)],
                    'cmyk' => ['space' => 'cmyk', 'v' => $this->normalizeVector($color['v'], 4)],
                    'gray', 'grey' => ['space' => 'gray', 'v' => [$this->normalizeComponent((float)($color['v'][0] ?? 0))]],
                    default => null,
                };
            }

            // Chaves abreviadas
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

            // Lista posicional
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
