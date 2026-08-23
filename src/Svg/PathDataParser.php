<?php

declare(strict_types=1);

namespace Pagyra\Svg;

/**
 * Normalizes SVG path commands to absolute M/L/C/Z segments.
 * Quadratic and elliptical-arc commands are converted to cubic curves to match pagyra-js.
 */
final class PathDataParser
{
    private string $source = '';
    private int $index = 0;

    /** @return list<array<string,float|string>> */
    public function parse(?string $data): array
    {
        if ($data === null || $data === '') return [];

        $this->source = $data;
        $this->index = 0;
        $segments = [];
        $command = null;
        $x = $y = $startX = $startY = 0.0;
        $prevCubicX = $prevCubicY = $prevQuadX = $prevQuadY = null;

        while (true) {
            $this->skipSeparators();
            if ($this->done()) break;
            $char = $this->source[$this->index];
            if ($this->isCommand($char)) {
                $command = $char;
                $this->index++;
            } elseif ($command === null) {
                break;
            }

            switch ($command) {
                case 'M': case 'm':
                    $relative = $command === 'm';
                    $point = $this->pair();
                    if ($point === null) return $segments;
                    $x = $relative ? $x + $point[0] : $point[0];
                    $y = $relative ? $y + $point[1] : $point[1];
                    $startX = $x; $startY = $y;
                    $segments[] = ['type' => 'M', 'x' => $x, 'y' => $y];
                    $prevCubicX = $prevCubicY = $prevQuadX = $prevQuadY = null;
                    while (true) {
                        $this->skipSeparators();
                        if ($this->done() || $this->isCommand($this->source[$this->index])) break;
                        $point = $this->pair();
                        if ($point === null) return $segments;
                        $x = $relative ? $x + $point[0] : $point[0];
                        $y = $relative ? $y + $point[1] : $point[1];
                        $segments[] = ['type' => 'L', 'x' => $x, 'y' => $y];
                    }
                    break;

                case 'L': case 'l':
                    $relative = $command === 'l';
                    while (($point = $this->pair()) !== null) {
                        $x = $relative ? $x + $point[0] : $point[0];
                        $y = $relative ? $y + $point[1] : $point[1];
                        $segments[] = ['type' => 'L', 'x' => $x, 'y' => $y];
                    }
                    $prevCubicX = $prevCubicY = $prevQuadX = $prevQuadY = null;
                    break;

                case 'H': case 'h':
                    $relative = $command === 'h';
                    while (($value = $this->number()) !== null) {
                        $x = $relative ? $x + $value : $value;
                        $segments[] = ['type' => 'L', 'x' => $x, 'y' => $y];
                    }
                    $prevCubicX = $prevCubicY = $prevQuadX = $prevQuadY = null;
                    break;

                case 'V': case 'v':
                    $relative = $command === 'v';
                    while (($value = $this->number()) !== null) {
                        $y = $relative ? $y + $value : $value;
                        $segments[] = ['type' => 'L', 'x' => $x, 'y' => $y];
                    }
                    $prevCubicX = $prevCubicY = $prevQuadX = $prevQuadY = null;
                    break;

                case 'C': case 'c':
                    $relative = $command === 'c';
                    while (true) {
                        $p1 = $this->pair(); $p2 = $this->pair(); $end = $this->pair();
                        if ($p1 === null || $p2 === null || $end === null) break;
                        $x1 = $relative ? $x + $p1[0] : $p1[0];
                        $y1 = $relative ? $y + $p1[1] : $p1[1];
                        $x2 = $relative ? $x + $p2[0] : $p2[0];
                        $y2 = $relative ? $y + $p2[1] : $p2[1];
                        $x = $relative ? $x + $end[0] : $end[0];
                        $y = $relative ? $y + $end[1] : $end[1];
                        $segments[] = ['type' => 'C', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'x' => $x, 'y' => $y];
                        $prevCubicX = $x2; $prevCubicY = $y2;
                        $prevQuadX = $prevQuadY = null;
                    }
                    break;

                case 'S': case 's':
                    $relative = $command === 's';
                    while (true) {
                        $p2 = $this->pair(); $end = $this->pair();
                        if ($p2 === null || $end === null) break;
                        $x1 = $prevCubicX !== null ? 2 * $x - $prevCubicX : $x;
                        $y1 = $prevCubicY !== null ? 2 * $y - $prevCubicY : $y;
                        $x2 = $relative ? $x + $p2[0] : $p2[0];
                        $y2 = $relative ? $y + $p2[1] : $p2[1];
                        $x = $relative ? $x + $end[0] : $end[0];
                        $y = $relative ? $y + $end[1] : $end[1];
                        $segments[] = ['type' => 'C', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'x' => $x, 'y' => $y];
                        $prevCubicX = $x2; $prevCubicY = $y2;
                        $prevQuadX = $prevQuadY = null;
                    }
                    break;

                case 'Q': case 'q': case 'T': case 't':
                    $relative = ctype_lower($command);
                    $smooth = strtolower($command) === 't';
                    while (true) {
                        if ($smooth) {
                            $control = [
                                $prevQuadX !== null ? 2 * $x - $prevQuadX : $x,
                                $prevQuadY !== null ? 2 * $y - $prevQuadY : $y,
                            ];
                            $end = $this->pair();
                        } else {
                            $control = $this->pair();
                            $end = $this->pair();
                        }
                        if ($control === null || $end === null) break;
                        $cx = $smooth ? $control[0] : ($relative ? $x + $control[0] : $control[0]);
                        $cy = $smooth ? $control[1] : ($relative ? $y + $control[1] : $control[1]);
                        $ex = $relative ? $x + $end[0] : $end[0];
                        $ey = $relative ? $y + $end[1] : $end[1];
                        $cubic = $this->quadraticToCubic($x, $y, $cx, $cy, $ex, $ey);
                        $segments[] = $cubic;
                        $x = $ex; $y = $ey;
                        $prevCubicX = $cubic['x2']; $prevCubicY = $cubic['y2'];
                        $prevQuadX = $cx; $prevQuadY = $cy;
                    }
                    break;

                case 'A': case 'a':
                    $relative = $command === 'a';
                    while (true) {
                        $rx = $this->number();
                        $ry = $this->number();
                        $rotation = $this->number();
                        $largeArc = $this->flag();
                        $sweep = $this->flag();
                        $end = $this->pair();
                        if ($rx === null || $ry === null || $rotation === null || $largeArc === null || $sweep === null || $end === null) break;

                        $ex = $relative ? $x + $end[0] : $end[0];
                        $ey = $relative ? $y + $end[1] : $end[1];
                        $curves = $this->arcToCubicCurves($x, $y, $rx, $ry, $rotation, $largeArc === 1, $sweep === 1, $ex, $ey);
                        if ($curves === []) {
                            if ($x !== $ex || $y !== $ey) $segments[] = ['type' => 'L', 'x' => $ex, 'y' => $ey];
                        } else {
                            foreach ($curves as $curve) {
                                $segments[] = [
                                    'type' => 'C',
                                    'x1' => $curve[0], 'y1' => $curve[1],
                                    'x2' => $curve[2], 'y2' => $curve[3],
                                    'x' => $curve[4], 'y' => $curve[5],
                                ];
                            }
                        }
                        $x = $ex; $y = $ey;
                        $last = $curves === [] ? null : $curves[array_key_last($curves)];
                        $prevCubicX = $last[2] ?? null;
                        $prevCubicY = $last[3] ?? null;
                        $prevQuadX = $prevQuadY = null;
                    }
                    break;

                case 'Z': case 'z':
                    if ($x !== $startX || $y !== $startY) $segments[] = ['type' => 'L', 'x' => $startX, 'y' => $startY];
                    $segments[] = ['type' => 'Z'];
                    $x = $startX; $y = $startY;
                    $prevCubicX = $prevCubicY = $prevQuadX = $prevQuadY = null;
                    $command = null;
                    break;

                default:
                    return $segments;
            }
        }

        return $segments;
    }

    /** @return array{0:float,1:float}|null */
    private function pair(): ?array
    {
        $x = $this->number();
        $y = $this->number();
        return $x === null || $y === null ? null : [$x, $y];
    }

    private function flag(): ?int
    {
        $this->skipSeparators();
        if ($this->done()) return null;
        $char = $this->source[$this->index];
        if ($char === '0' || $char === '1') {
            $this->index++;
            return $char === '1' ? 1 : 0;
        }
        $value = $this->number();
        if ($value === null) return null;
        return $value == 0.0 ? 0 : 1;
    }

    private function number(): ?float
    {
        $this->skipSeparators();
        if ($this->done()) return null;
        if (preg_match('/\G[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/A', $this->source, $match, 0, $this->index) !== 1) return null;
        $this->index += strlen($match[0]);
        $value = (float) $match[0];
        return is_finite($value) ? $value : null;
    }

    private function skipSeparators(): void
    {
        $length = strlen($this->source);
        while ($this->index < $length && str_contains(", \t\n\r", $this->source[$this->index])) $this->index++;
    }

    private function done(): bool
    {
        return $this->index >= strlen($this->source);
    }

    private function isCommand(string $char): bool
    {
        return str_contains('MmLlHhVvCcSsQqTtAaZz', $char);
    }

    /** @return array{type:string,x1:float,y1:float,x2:float,y2:float,x:float,y:float} */
    private function quadraticToCubic(float $x0, float $y0, float $cx, float $cy, float $x, float $y): array
    {
        return [
            'type' => 'C',
            'x1' => $x0 + (2.0 / 3.0) * ($cx - $x0),
            'y1' => $y0 + (2.0 / 3.0) * ($cy - $y0),
            'x2' => $x + (2.0 / 3.0) * ($cx - $x),
            'y2' => $y + (2.0 / 3.0) * ($cy - $y),
            'x' => $x,
            'y' => $y,
        ];
    }

    /** @return list<array{0:float,1:float,2:float,3:float,4:float,5:float}> */
    private function arcToCubicCurves(
        float $x0,
        float $y0,
        float $rx,
        float $ry,
        float $angle,
        bool $largeArc,
        bool $sweep,
        float $x,
        float $y,
    ): array {
        if ($x0 === $x && $y0 === $y) return [];
        $rx = abs($rx); $ry = abs($ry);
        if ($rx === 0.0 || $ry === 0.0) return [];

        $rad = $angle * M_PI / 180.0;
        $cosAngle = cos($rad); $sinAngle = sin($rad);
        $dx2 = ($x0 - $x) / 2.0; $dy2 = ($y0 - $y) / 2.0;
        $x1p = $cosAngle * $dx2 + $sinAngle * $dy2;
        $y1p = -$sinAngle * $dx2 + $cosAngle * $dy2;

        $rxSq = $rx * $rx; $rySq = $ry * $ry;
        $x1pSq = $x1p * $x1p; $y1pSq = $y1p * $y1p;
        $radiiCheck = $x1pSq / $rxSq + $y1pSq / $rySq;
        if ($radiiCheck > 1.0) {
            $scale = sqrt($radiiCheck);
            $rx *= $scale; $ry *= $scale;
            $rxSq = $rx * $rx; $rySq = $ry * $ry;
        }

        $sign = $largeArc === $sweep ? -1.0 : 1.0;
        $denominator = $rxSq * $y1pSq + $rySq * $x1pSq;
        $sq = $denominator === 0.0 ? 0.0 : ($rxSq * $rySq - $rxSq * $y1pSq - $rySq * $x1pSq) / $denominator;
        $coef = $sign * sqrt(max(0.0, $sq));
        $cxp = ($coef * $rx * $y1p) / $ry;
        $cyp = (-$coef * $ry * $x1p) / $rx;
        $cx = $cosAngle * $cxp - $sinAngle * $cyp + ($x0 + $x) / 2.0;
        $cy = $sinAngle * $cxp + $cosAngle * $cyp + ($y0 + $y) / 2.0;

        $startAngle = $this->angleBetween(1.0, 0.0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $deltaAngle = $this->angleBetween(
            ($x1p - $cxp) / $rx,
            ($y1p - $cyp) / $ry,
            (-$x1p - $cxp) / $rx,
            (-$y1p - $cyp) / $ry,
        );
        if (!$sweep && $deltaAngle > 0.0) $deltaAngle -= 2.0 * M_PI;
        elseif ($sweep && $deltaAngle < 0.0) $deltaAngle += 2.0 * M_PI;

        $segmentCount = (int) ceil(abs($deltaAngle) / (M_PI / 2.0));
        if ($segmentCount <= 0) return [];
        $delta = $deltaAngle / $segmentCount;
        $t = (4.0 / 3.0) * tan($delta / 4.0);
        $start = $startAngle;
        $prevX = $x0; $prevY = $y0;
        $curves = [];

        for ($i = 0; $i < $segmentCount; $i++) {
            $end = $start + $delta;
            $sinStart = sin($start); $cosStart = cos($start);
            $sinEnd = sin($end); $cosEnd = cos($end);
            $x2 = $cx + $rx * $cosAngle * $cosEnd - $ry * $sinAngle * $sinEnd;
            $y2 = $cy + $rx * $sinAngle * $cosEnd + $ry * $cosAngle * $sinEnd;
            $dx1 = -$rx * $cosAngle * $sinStart - $ry * $sinAngle * $cosStart;
            $dy1 = -$rx * $sinAngle * $sinStart + $ry * $cosAngle * $cosStart;
            $dxEnd = -$rx * $cosAngle * $sinEnd - $ry * $sinAngle * $cosEnd;
            $dyEnd = -$rx * $sinAngle * $sinEnd + $ry * $cosAngle * $cosEnd;
            $curves[] = [
                $prevX + $t * $dx1,
                $prevY + $t * $dy1,
                $x2 - $t * $dxEnd,
                $y2 - $t * $dyEnd,
                $x2,
                $y2,
            ];
            $prevX = $x2; $prevY = $y2; $start = $end;
        }

        return $curves;
    }

    private function angleBetween(float $ux, float $uy, float $vx, float $vy): float
    {
        $dot = $ux * $vx + $uy * $vy;
        $length = sqrt(($ux * $ux + $uy * $uy) * ($vx * $vx + $vy * $vy));
        $ratio = $length === 0.0 ? 0.0 : $dot / $length;
        $clamped = max(-1.0, min(1.0, $ratio));
        $sign = $ux * $vy - $uy * $vx < 0.0 ? -1.0 : 1.0;
        return $sign * acos($clamped);
    }
}
