<?php

declare(strict_types=1);

namespace Pagyra\Css\Length;

use Pagyra\Units\Units;

final readonly class LengthParser
{
    public function __construct(
        private float $viewportWidth = 794.0,
        private float $viewportHeight = 1123.0,
    ) {
    }

    public function parseLength(string $value): float|RelativeLength|null
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || $normalized === 'auto') {
            return null;
        }

        if (!preg_match('/^(-?\d+(?:\.\d+)?)(px|pt|vh|vw|em|rem|cm|mm|q|in|pc)?$/i', $normalized, $match)) {
            return null;
        }

        $numeric = (float) $match[1];
        $unit = strtolower($match[2] ?? 'px');

        return match ($unit) {
            'px' => $numeric,
            'pt' => Units::ptToPx($numeric),
            'cm' => Units::cmToPx($numeric),
            'mm' => Units::mmToPx($numeric),
            'q' => Units::qToPx($numeric),
            'in' => Units::inToPx($numeric),
            'pc' => Units::pcToPx($numeric),
            'vh' => ($numeric / 100) * $this->viewportHeight,
            'vw' => ($numeric / 100) * $this->viewportWidth,
            'em', 'rem' => new RelativeLength($unit, $numeric),
            default => null,
        };
    }

    public function parseLengthOrPercent(string $value): float|RelativeLength|PercentLength|null
    {
        $parsed = $this->parseLength($value);
        if ($parsed !== null) {
            return $parsed;
        }

        $trimmed = trim($value);
        if (!preg_match('/^(-?\d+(?:\.\d+)?)%$/', $trimmed, $match)) {
            return null;
        }

        return new PercentLength(((float) $match[1]) / 100);
    }

    public function parseLengthOrAuto(string $value): float|RelativeLength|PercentLength|string|null
    {
        if (strtolower(trim($value)) === 'auto') {
            return 'auto';
        }

        return $this->parseLengthOrPercent($value);
    }
}
