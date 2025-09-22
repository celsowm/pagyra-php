<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Graphics\Gradient;

use Celsowm\PagyraPhp\Core\PdfBuilder;

/**
 * Responsabilidade única: criar /Function (Type 2 e Type 3 “stitching”).
 */
final class PdfGradientFactory
{
    public function __construct(private PdfBuilder $pdf) {}

    /**
     * @param array<int, array{offset:float, color:string|array}> $stops
     * @return int Object ID da função composta (Type 3)
     */
    /**
     * @param array<int, array{offset:float, color:string|array}> $stops
     * @return int Object ID da função (Type 2 se houver 2 stops; Type 3 se houver 3+)
     */
    public function buildStitchingFn(array $stops): int
    {
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('Gradient precisa de pelo menos 2 stops.');
        }

        // 1) Sanitiza offsets e ordena
        $clean = [];
        foreach ($stops as $s) {
            $off = $this->clamp01((float)($s['offset'] ?? 0.0));
            $clr = $s['color'] ?? '#000';
            $clean[] = ['offset' => $off, 'color' => $clr];
        }
        usort($clean, fn($a, $b) => $a['offset'] <=> $b['offset']);

        // Remove offsets duplicados (mantém o último)
        $norm = [];
        foreach ($clean as $s) {
            if (!empty($norm) && abs($s['offset'] - end($norm)['offset']) < 1e-9) {
                $norm[count($norm) - 1] = $s;
            } else {
                $norm[] = $s;
            }
        }
        if (count($norm) < 2) {
            throw new \InvalidArgumentException('Após normalização, restaram menos de 2 stops válidos.');
        }

        // 2) Gera Type 2 por intervalo
        $funcIds = [];
        $bounds  = [];
        $encode  = [];

        $nStops = count($norm);
        for ($i = 0; $i < $nStops - 1; $i++) {
            $C0 = $this->toRgb($norm[$i]['color']);
            $C1 = $this->toRgb($norm[$i + 1]['color']);

            $fid = $this->pdf->newObjectId();
            $this->pdf->setObject($fid, sprintf(
                '<< /FunctionType 2 /Domain [0 1] /C0 [%s %s %s] /C1 [%s %s %s] /N 1 >>',
                $this->f($C0[0]),
                $this->f($C0[1]),
                $this->f($C0[2]),
                $this->f($C1[0]),
                $this->f($C1[1]),
                $this->f($C1[2])
            ));
            $funcIds[] = $fid;

            if ($i < $nStops - 2) {
                // Bounds estritamente crescentes dentro de (0,1)
                $next = $this->clamp01((float)$norm[$i + 1]['offset']);
                $prev = !empty($bounds) ? (float) end($bounds) : -INF;
                if ($next <= $prev) {
                    $next = min(1.0, $prev + 1e-6);
                }
                $bounds[] = $this->f($next);
            }

            // Encode: 0..1 por subfunção
            $encode[] = '0 1';
        }

        // ✅ Se só existe UM intervalo (2 stops), devolve a própria Type 2
        if (count($funcIds) === 1) {
            return $funcIds[0];
        }

        // 3) Monta Type 3 (stitching) corretamente
        $fns = implode(' ', array_map(static fn($id) => "$id 0 R", $funcIds));
        $b   = empty($bounds) ? '' : ' /Bounds [ ' . implode(' ', $bounds) . ' ]';
        $e   = ' /Encode [ ' . implode(' ', $encode) . ' ]';

        $id = $this->pdf->newObjectId();
        $this->pdf->setObject($id, "<< /FunctionType 3 /Domain [0 1] /Functions [ {$fns} ]{$b}{$e} >>");
        return $id;
    }


    /** @return array{0:float,1:float,2:float} RGB em [0..1] */
    private function toRgb(string|array $color): array
    {
        // String: #rgb, #rrggbb, rgb()/rgba()
        if (is_string($color)) {
            $s = trim($color);

            // rgb()/rgba()
            if (preg_match('/^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})(?:\s*,\s*([0-9]*\.?[0-9]+))?\s*\)$/i', $s, $m)) {
                $r = $this->clamp01(((int)$m[1]) / 255.0);
                $g = $this->clamp01(((int)$m[2]) / 255.0);
                $b = $this->clamp01(((int)$m[3]) / 255.0);
                return [$r, $g, $b];
            }

            // #rgb / #rrggbb
            $h = ltrim($s, '#');
            if (strlen($h) === 3 && ctype_xdigit($h)) {
                $r = hexdec($h[0] . $h[0]);
                $g = hexdec($h[1] . $h[1]);
                $b = hexdec($h[2] . $h[2]);
                return [$r / 255, $g / 255, $b / 255];
            }
            if (strlen($h) === 6 && ctype_xdigit($h)) {
                $r = hexdec(substr($h, 0, 2));
                $g = hexdec(substr($h, 2, 2));
                $b = hexdec(substr($h, 4, 2));
                return [$r / 255, $g / 255, $b / 255];
            }
        }

        // Array: formatos normalizados
        if (is_array($color)) {
            // Forma com space/v
            if (isset($color['space'], $color['v']) && is_array($color['v'])) {
                $space = strtolower((string)$color['space']);
                return match ($space) {
                    'rgb'  => $this->vecToRgb($color['v']),
                    'gray', 'grey' => $this->grayToRgb((float)($color['v'][0] ?? 0.0)),
                    'cmyk' => $this->cmykToRgb($color['v']),
                    default => [0.0, 0.0, 0.0],
                };
            }

            // Atalhos por chave
            if (isset($color['rgb']) && is_array($color['rgb'])) {
                return $this->vecToRgb($color['rgb']);
            }
            if (isset($color['cmyk']) && is_array($color['cmyk'])) {
                return $this->cmykToRgb($color['cmyk']);
            }
            if (array_key_exists('gray', $color) || array_key_exists('grey', $color)) {
                $gval = array_key_exists('gray', $color) ? $color['gray'] : $color['grey'];
                $g = is_array($gval) ? ($gval[0] ?? 0.0) : $gval;
                return $this->grayToRgb((float)$g);
            }

            // Lista posicional
            if (array_is_list($color)) {
                $n = count($color);
                if ($n === 3) return $this->vecToRgb($color);
                if ($n === 4) return $this->cmykToRgb($color);
                if ($n === 1) return $this->grayToRgb((float)$color[0]);
            }
        }

        // Fallback (preto)
        return [0.0, 0.0, 0.0];
    }

    /** Converte vetor (possível 0..255) para RGB [0..1] com clamp. */
    private function vecToRgb(array $v): array
    {
        $v = array_slice(array_values($v), 0, 3);
        if (count($v) < 3) $v = array_pad($v, 3, 0.0);

        // detecta escala 0..255
        $over1 = false;
        for ($i = 0; $i < 3; $i++) {
            if ((float)$v[$i] > 1.0) {
                $over1 = true;
                break;
            }
        }

        $r = $this->clamp01((float)$v[0] / ($over1 ? 255.0 : 1.0));
        $g = $this->clamp01((float)$v[1] / ($over1 ? 255.0 : 1.0));
        $b = $this->clamp01((float)$v[2] / ($over1 ? 255.0 : 1.0));
        return [$r, $g, $b];
    }

    /** Converte gray para RGB [0..1]. */
    private function grayToRgb(float $g): array
    {
        $g = $this->clamp01($g);
        return [$g, $g, $g];
    }

    /** Converte CMYK [0..1] (aceitando 0..255) para RGB [0..1]. */
    private function cmykToRgb(array $v): array
    {
        $v = array_slice(array_values($v), 0, 4);
        if (count($v) < 4) $v = array_pad($v, 4, 0.0);

        // detecta escala 0..255
        $over1 = false;
        for ($i = 0; $i < 4; $i++) {
            if ((float)$v[$i] > 1.0) {
                $over1 = true;
                break;
            }
        }
        [$c, $m, $y, $k] = [
            $this->clamp01((float)$v[0] / ($over1 ? 255.0 : 1.0)),
            $this->clamp01((float)$v[1] / ($over1 ? 255.0 : 1.0)),
            $this->clamp01((float)$v[2] / ($over1 ? 255.0 : 1.0)),
            $this->clamp01((float)$v[3] / ($over1 ? 255.0 : 1.0)),
        ];

        $r = 1.0 - min(1.0, $c + $k);
        $g = 1.0 - min(1.0, $m + $k);
        $b = 1.0 - min(1.0, $y + $k);
        return [$r, $g, $b];
    }

    /** Clamp [0..1] com proteção para NaN/INF. */
    private function clamp01(float $x): float
    {
        if (!is_finite($x)) $x = 0.0;
        return max(0.0, min(1.0, $x));
    }

    /** Formata com ponto e 6 casas. */
    private function f(float $x): string
    {
        if (!is_finite($x)) $x = 0.0;
        $x = $this->clamp01($x);
        return sprintf('%.6F', $x);
    }
}
