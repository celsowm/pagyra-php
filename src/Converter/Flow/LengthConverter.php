<?php
declare(strict_types=1);

namespace Celsowm\PagyraPhp\Converter\Flow;

final class LengthConverter
{
    public function parseLength(string $value, float $default): float
    {
        return $this->convertLength($value, $default, $default);
    }

    public function parseLengthOptional(
        string $value,
        float $reference,
        float $fallback,
        bool $unitlessIsMultiplier = false
    ): float {
        return $this->convertLength($value, $reference, $fallback, $unitlessIsMultiplier);
    }

    private function convertLength(
        string $value,
        float $reference,
        float $fallback,
        bool $unitlessIsMultiplier = false
    ): float {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'normal') {
            return $fallback;
        }
        if (is_numeric($value)) {
            $number = (float)$value;
            return $unitlessIsMultiplier ? $number * $reference : $number;
        }
        if (preg_match('/^([0-9]*\.?[0-9]+)(px|pt|em|rem)?$/i', $value, $matches) === 1) {
            $number = (float)$matches[1];
            $unit = strtolower($matches[2] ?? '');
            if ($unit === '') {
                return $unitlessIsMultiplier ? $number * $reference : $number;
            }

            return match ($unit) {
                'px' => $number * 0.75,
                'em', 'rem' => $number * $reference,
                default => $number,
            };
        }

        return $fallback;
    }
}
