<?php

declare(strict_types=1);

namespace Pagyra\Svg;

/**
 * Normalizes the core SVG path command set to absolute M/L/C/Z segments.
 * Quadratic commands are converted to cubic curves to match pagyra-js.
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
}
