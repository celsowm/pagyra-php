<?php

declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

use Celsowm\PagyraPhp\Html\Style\ComputedStyle;

final class MarginCalculator
{
    public function __construct(private LengthConverter $lengthConverter) {}

    /**
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    public function extractMarginBox(ComputedStyle $style, float $reference): array
    {
        $map = $style->toArray();
        $margins = ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];

        if (isset($map['margin'])) {
            $rawValues = preg_split('/\s+/', trim((string)$map['margin'])) ?: [];
            $values = [];
            foreach ($rawValues as $value) {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }
                $values[] = $value;
            }
            if ($values !== []) {
                $count = count($values);
                if ($count === 1) {
                    $values = array_fill(0, 4, $values[0]);
                } elseif ($count === 2) {
                    $values = [$values[0], $values[1], $values[0], $values[1]];
                } elseif ($count === 3) {
                    $values = [$values[0], $values[1], $values[2], $values[1]];
                } else {
                    $values = array_slice($values, 0, 4);
                }
                $sides = ['top', 'right', 'bottom', 'left'];
                foreach ($sides as $index => $side) {
                    $margins[$side] = $this->parseMarginComponent($values[$index], $reference, $margins[$side]);
                }
            }
        }

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $property = 'margin-' . $side;
            if (!isset($map[$property])) {
                continue;
            }
            $margins[$side] = $this->parseMarginComponent($map[$property], $reference, $margins[$side]);
        }

        return $margins;
    }

    public function extractPaddingBox(ComputedStyle $style, float $reference): array
    {
        $map = $style->toArray();
        $pad = ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0];

        if (isset($map['padding'])) {
            $vals = preg_split('/\s+/', trim((string)$map['padding'])) ?: [];
            $vals = array_values(array_filter(array_map('trim', $vals), fn($v) => $v !== ''));
            $c = count($vals);
            if ($c === 1)       $vals = [$vals[0], $vals[0], $vals[0], $vals[0]];
            elseif ($c === 2)   $vals = [$vals[0], $vals[1], $vals[0], $vals[1]];
            elseif ($c === 3)   $vals = [$vals[0], $vals[1], $vals[2], $vals[1]];
            else                $vals = array_slice($vals, 0, 4);

            foreach (['top', 'right', 'bottom', 'left'] as $i => $side) {
                $pad[$side] = $this->lengthConverter->parseLengthOptional($vals[$i], $reference, $pad[$side]);
            }
        }

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $prop = 'padding-' . $side;
            if (isset($map[$prop])) {
                $pad[$side] = $this->lengthConverter->parseLengthOptional($map[$prop], $reference, $pad[$side]);
            }
        }

        return $pad;
    }

    private function parseMarginComponent(string $value, float $reference, float $fallback): float
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || $normalized === 'auto' || $normalized === 'inherit') {
            return $fallback;
        }

        return $this->lengthConverter->parseLengthOptional($value, $reference, $fallback);
    }
}
