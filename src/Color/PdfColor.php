<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Color;

final class PdfColor
{
    public function getFillOps(string|array|null $color): string
    {
        $spec = (!is_array($color) || !isset($color['space'])) ? $this->normalize($color) : $color;

        if ($spec === null) {
            return '';
        }
        return $this->buildPdfOps($spec, 'fill');
    }

    public function getStrokeOps(string|array|null $color): string
    {
        $spec = (!is_array($color) || !isset($color['space'])) ? $this->normalize($color) : $color;

        if ($spec === null) {
            return '';
        }
        return $this->buildPdfOps($spec, 'stroke');
    }

    public function normalize(string|array|null $color): ?array
    {
        if ($color === null) {
            return null;
        }
        if (is_string($color)) {
            $s = ltrim(trim($color), '#');
            if (preg_match('/^[0-9a-fA-F]{3}$/', $s)) {
                $r = hexdec(str_repeat($s[0], 2));
                $g = hexdec(str_repeat($s[1], 2));
                $b = hexdec(str_repeat($s[2], 2));
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
        if (is_array($color)) {
            if (isset($color['space']) && isset($color['v'])) {
                return $color;
            }
            if (isset($color['rgb'])) return ['space' => 'rgb', 'v' => $this->normalizeVector($color['rgb'], 3)];
            if (isset($color['cmyk'])) return ['space' => 'cmyk', 'v' => $this->normalizeVector($color['cmyk'], 4)];
            if (isset($color['gray'])) {
                $v = is_array($color['gray']) ? ($color['gray'][0] ?? null) : $color['gray'];
                return is_numeric($v) ? ['space' => 'gray', 'v' => [$this->normalizeComponent((float)$v)]] : null;
            }
            if (function_exists('array_is_list') && array_is_list($color)) {
                if (count($color) == 3) return ['space' => 'rgb', 'v' => $this->normalizeVector($color, 3)];
                if (count($color) == 4) return ['space' => 'cmyk', 'v' => $this->normalizeVector($color, 4)];
                if (count($color) == 1) return ['space' => 'gray', 'v' => [$this->normalizeComponent((float)$color[0])]];
            }
        }
        return null;
    }

    private function buildPdfOps(array $spec, string $type): string
    {
        $space = $spec['space'] ?? null;
        $v = $spec['v'] ?? [];

        $opsMap = [
            'fill'   => ['rgb' => 'rg', 'gray' => 'g', 'cmyk' => 'k'],
            'stroke' => ['rgb' => 'RG', 'gray' => 'G', 'cmyk' => 'K'],
        ];

        $operator = $opsMap[$type][$space] ?? null;

        if ($operator === null || empty($v)) {
            return '';
        }

        $values = implode(' ', array_map(fn($c) => sprintf('%.3F', $c), $v));
        return "{$values} {$operator}\n";
    }

    private function normalizeVector(array $v, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->normalizeComponent((float)($v[$i] ?? 0));
        }
        return $out;
    }

    private function normalizeComponent(float $x): float
    {
        return max(0.0, min(1.0, $x > 1.0 ? $x / 255.0 : $x));
    }
}