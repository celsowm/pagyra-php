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
    public function buildStitchingFn(array $stops): int
    {
        usort($stops, fn($a, $b) => $a['offset'] <=> $b['offset']);
        if (count($stops) < 2) {
            throw new \InvalidArgumentException('Gradient precisa de pelo menos 2 stops.');
        }

        $funcIds = [];
        $bounds  = [];
        $encode  = [];

        for ($i = 0; $i < count($stops) - 1; $i++) {
            $C0 = $this->toRgb($stops[$i]['color']);
            $C1 = $this->toRgb($stops[$i + 1]['color']);

            $fid = $this->pdf->newObjectId();
            $this->pdf->setObject($fid, sprintf(
                '<< /FunctionType 2 /Domain [0 1] /C0 [%.6F %.6F %.6F] /C1 [%.6F %.6F %.6F] /N 1 >>',
                $C0[0],
                $C0[1],
                $C0[2],
                $C1[0],
                $C1[1],
                $C1[2]
            ));
            $funcIds[] = $fid;

            if ($i < count($stops) - 2) {
                $bounds[] = sprintf('%.6F', $stops[$i + 1]['offset']);
            }
            $encode[] = '0 1';
        }

        $fns = implode(' ', array_map(fn($id) => "$id 0 R", $funcIds));
        $b   = empty($bounds) ? '' : ' /Bounds [ ' . implode(' ', $bounds) . ' ]';
        $e   = ' /Encode [ ' . implode(' ', $encode) . ' ]';

        $id = $this->pdf->newObjectId();
        $this->pdf->setObject($id, "<< /FunctionType 3 /Domain [0 1] /Functions [ {$fns} ]{$b}{$e} >>");
        return $id;
    }

    /** @return array{0:float,1:float,2:float} valores 0..1 */
    private function toRgb(string|array $color): array
    {
        // #rgb / #rrggbb
        if (is_string($color)) {
            $s = ltrim(trim($color), '#');
            if (strlen($s) === 3) {
                $r = hexdec($s[0] . $s[0]);
                $g = hexdec($s[1] . $s[1]);
                $b = hexdec($s[2] . $s[2]);
                return [$r / 255, $g / 255, $b / 255];
            }
            if (strlen($s) === 6) {
                $r = hexdec(substr($s, 0, 2));
                $g = hexdec(substr($s, 2, 2));
                $b = hexdec(substr($s, 4, 2));
                return [$r / 255, $g / 255, $b / 255];
            }
        }

        // [{ space:'rgb'|'gray'|'cmyk', v:[...] }] ou vetor [r,g,b]
        if (is_array($color)) {
            if (isset($color['space'], $color['v'])) {
                $v = $color['v'];
                switch ($color['space']) {
                    case 'rgb':
                        return [(float)$v[0], (float)$v[1], (float)$v[2]];
                    case 'gray':
                        return [(float)$v[0], (float)$v[0], (float)$v[0]];
                    case 'cmyk':
                        [$c, $m, $y, $k] = $v;
                        return [1 - min(1, $c + $k), 1 - min(1, $m + $k), 1 - min(1, $y + $k)];
                }
            }
            if (count($color) === 3) {
                return [(float)$color[0], (float)$color[1], (float)$color[2]];
            }
        }

        return [0, 0, 0];
    }
}
