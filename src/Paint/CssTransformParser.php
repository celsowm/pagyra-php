<?php

declare(strict_types=1);

namespace Pagyra\Paint;

/**
 * CSS 2D transform parser. Functions are multiplied left-to-right in the same matrix convention
 * used by pagyra-js. Percent translations resolve against the transformed border-box dimensions.
 */
final class CssTransformParser
{
    public function parse(?string $raw, float $referenceWidth = 0.0, float $referenceHeight = 0.0): ?TransformMatrix
    {
        $raw = trim($raw ?? '');
        if ($raw === '' || strtolower($raw) === 'none') return null;

        if (preg_match_all('/([a-zA-Z0-9]+)\(([^)]*)\)/', $raw, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $matrix = TransformMatrix::identity();
        $found = false;
        foreach ($matches as $match) {
            $next = $this->functionMatrix(strtolower($match[1]), $match[2], $referenceWidth, $referenceHeight);
            if (!$next instanceof TransformMatrix) continue;
            $matrix = $matrix->multiply($next);
            $found = true;
        }

        return $found ? $matrix : null;
    }

    /** @return array{0:float,1:float} local transform-origin offset from the border-box top-left */
    public function origin(?string $raw, float $width, float $height): array
    {
        $tokens = preg_split('/\s+/', trim($raw ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) return [$width / 2.0, $height / 2.0];

        if (count($tokens) === 1) {
            $token = strtolower($tokens[0]);
            if (in_array($token, ['top', 'bottom'], true)) {
                return [$width / 2.0, $this->originComponent($token, $height, false)];
            }
            return [$this->originComponent($token, $width, true), $height / 2.0];
        }

        $first = strtolower($tokens[0]);
        $second = strtolower($tokens[1]);
        if (in_array($first, ['top', 'bottom'], true) || in_array($second, ['left', 'right'], true)) {
            [$first, $second] = [$second, $first];
        }

        return [
            $this->originComponent($first, $width, true),
            $this->originComponent($second, $height, false),
        ];
    }

    private function functionMatrix(string $type, string $args, float $width, float $height): ?TransformMatrix
    {
        $tokens = preg_split('/(?:\s*,\s*|\s+)/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match ($type) {
            'matrix' => count($tokens) >= 6 ? new TransformMatrix(
                $this->number($tokens[0], 1.0), $this->number($tokens[1]),
                $this->number($tokens[2]), $this->number($tokens[3], 1.0),
                $this->number($tokens[4]), $this->number($tokens[5]),
            ) : null,
            'translate' => new TransformMatrix(
                e: $this->length($tokens[0] ?? '0', $width),
                f: $this->length($tokens[1] ?? '0', $height),
            ),
            'translatex' => new TransformMatrix(e: $this->length($tokens[0] ?? '0', $width)),
            'translatey' => new TransformMatrix(f: $this->length($tokens[0] ?? '0', $height)),
            'scale' => $this->scale($tokens),
            'scalex' => new TransformMatrix(a: $this->scaleValue($tokens[0] ?? '1')),
            'scaley' => new TransformMatrix(d: $this->scaleValue($tokens[0] ?? '1')),
            'rotate' => $this->rotateValues($tokens),
            'skewx' => new TransformMatrix(c: tan($this->angleRadians($tokens[0] ?? '0'))),
            'skewy' => new TransformMatrix(b: tan($this->angleRadians($tokens[0] ?? '0'))),
            'skew' => $this->skew($tokens),
            default => null,
        };
    }

    /** @param list<string> $tokens */
    private function scale(array $tokens): TransformMatrix
    {
        $sx = $this->scaleValue($tokens[0] ?? '1');
        $sy = $this->scaleValue($tokens[1] ?? ($tokens[0] ?? '1'));
        return new TransformMatrix(a: $sx, d: $sy);
    }

    /** @param list<string> $tokens */
    private function rotateValues(array $tokens): TransformMatrix
    {
        $rotation = $this->rotate($tokens[0] ?? '0');
        if (count($tokens) < 3) return $rotation;

        $cx = $this->number($tokens[1]);
        $cy = $this->number($tokens[2]);

        return (new TransformMatrix(e: $cx, f: $cy))
            ->multiply($rotation)
            ->multiply(new TransformMatrix(e: -$cx, f: -$cy));
    }

    private function rotate(string $value): TransformMatrix
    {
        $angle = $this->angleRadians($value);
        $cos = cos($angle);
        $sin = sin($angle);
        return new TransformMatrix($cos, $sin, -$sin, $cos);
    }

    /** @param list<string> $tokens */
    private function skew(array $tokens): TransformMatrix
    {
        $x = tan($this->angleRadians($tokens[0] ?? '0'));
        $y = tan($this->angleRadians($tokens[1] ?? '0'));
        return new TransformMatrix(1.0, $y, $x, 1.0);
    }

    private function angleRadians(string $value): float
    {
        $value = strtolower(trim($value));
        if (preg_match('/^([-+]?(?:\d+\.?\d*|\.\d+))(deg|rad|grad|turn)?$/', $value, $m) !== 1) return 0.0;
        $number = (float) $m[1];
        return match ($m[2] ?? 'deg') {
            'rad' => $number,
            'grad' => $number * M_PI / 200.0,
            'turn' => $number * 2.0 * M_PI,
            default => $number * M_PI / 180.0,
        };
    }

    private function length(string $value, float $reference): float
    {
        $value = strtolower(trim($value));
        if (preg_match('/^([-+]?(?:\d+\.?\d*|\.\d+))%$/', $value, $m) === 1) {
            return $reference * (float) $m[1] / 100.0;
        }
        if (preg_match('/^([-+]?(?:\d+\.?\d*|\.\d+))(?:px)?$/', $value, $m) === 1) {
            return (float) $m[1];
        }
        return 0.0;
    }

    private function scaleValue(string $value): float
    {
        $value = trim($value);
        if (str_ends_with($value, '%')) return ((float) substr($value, 0, -1)) / 100.0;
        return is_numeric($value) ? (float) $value : 1.0;
    }

    private function number(string $value, float $fallback = 0.0): float
    {
        return is_numeric(trim($value)) ? (float) trim($value) : $fallback;
    }

    private function originComponent(string $value, float $reference, bool $horizontal): float
    {
        return match ($value) {
            'left' => $horizontal ? 0.0 : $reference / 2.0,
            'right' => $horizontal ? $reference : $reference / 2.0,
            'top' => $horizontal ? $reference / 2.0 : 0.0,
            'bottom' => $horizontal ? $reference / 2.0 : $reference,
            'center' => $reference / 2.0,
            default => $this->length($value, $reference),
        };
    }
}
