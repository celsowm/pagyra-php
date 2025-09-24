<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Css;

final class CssGradientParser
{
    public function parseLinear(?string $value): ?array
    {
        if (!is_string($value)) return null;
        if (!preg_match('/linear-gradient\s*\((.+)\)/i', $value, $m)) return null;

        $inside = trim($m[1]);
        $parts  = $this->splitTopLevel($inside);
        if ($parts === []) return null;

        // 1) direction / angle (optional first argument)
        $first = strtolower(trim($parts[0]));
        $deg = null;
        if (str_starts_with($first, 'to ')) {
            // CSS directions → degrees used by PdfBackgroundPainter::coordsFromAngle()
            // (we use 0° = to right; 90° = to top; -90° = to bottom)
            $map = [
                'to right'  => 0.0,
                'to left'   => 180.0,
                'to top'    => 90.0,
                'to bottom' => -90.0,
            ];
            $deg = $map[$first] ?? 0.0;
            array_shift($parts);
        } elseif (preg_match('/^-?\d+(\.\d+)?deg$/', $first)) {
            $deg = (float)rtrim($first, 'deg');
            array_shift($parts);
        }
        if ($deg === null) {
            // CSS default is "to bottom"
            $deg = -90.0;
        }

        // 2) color stops
        $stops = [];
        foreach ($parts as $i => $p) {
            $p = trim($p);
            // minimal: "#rrggbb [pos]" or "rgb()/rgba()" or named colors (you can expand later)
            // Here we just keep the color string; factory/normalizer will handle it.
            // Optional position parsing:
            if (preg_match('/^(#[0-9a-f]{3,6}|rgba?\([^)]+\)|[a-z]+)\s+([\d.]+%?)$/i', $p, $mm)) {
                $stops[] = ['color' => $mm[1], 'offset' => $this->posToOffset($mm[2])];
            } elseif (preg_match('/^(#[0-9a-f]{3,6}|rgba?\([^)]+\)|[a-z]+)$/i', $p, $mm)) {
                $stops[] = ['color' => $mm[1]];
            }
        }

        // ensure at least 2 stops and expand offsets if missing
        if (count($stops) < 2) return null;
        $n = count($stops);
        foreach ($stops as $i => &$s) {
            if (!array_key_exists('offset', $s)) {
                $s['offset'] = ($n === 1) ? 0.0 : $i / ($n - 1);
            }
            $s['offset'] = max(0.0, min(1.0, (float)$s['offset']));
        }

        return [
            'type'   => 'linear',
            'angle'  => (float)$deg,
            'stops'  => $stops,
            'extend' => [true, true],
        ];
    }

    private function splitTopLevel(string $s): array
    {
        $out = [];
        $buf = '';
        $level = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '(') {
                $level++;
                $buf .= $ch;
                continue;
            }
            if ($ch === ')') {
                $level--;
                $buf .= $ch;
                continue;
            }
            if ($ch === ',' && $level === 0) {
                $out[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return $out;
    }

    private function posToOffset(string $pos): float
    {
        $pos = trim($pos);
        if (str_ends_with($pos, '%')) return ((float)rtrim($pos, '%')) / 100.0;
        return (float)$pos; // already 0..1
    }
}
