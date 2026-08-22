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
        if (!preg_match('/^(-?\d+(?:\.\d+)?)(px|pt|vh|vw|em|rem|cm|mm|q|in|pc)?$/i', $normalized, $m)) {
            return null;
        }
        $n = (float) $m[1];
        $unit = strtolower($m[2] ?? 'px');
        return match ($unit) {
            'px' => $n,
            'pt' => Units::ptToPx($n),
            'cm' => Units::cmToPx($n),
            'mm' => Units::mmToPx($n),
            'q' => Units::qToPx($n),
            'in' => Units::inToPx($n),
            'pc' => Units::pcToPx($n),
            'vh' => ($n / 100) * $this->viewportHeight,
            'vw' => ($n / 100) * $this->viewportWidth,
            'em', 'rem' => new RelativeLength($unit, $n),
            default => null,
        };
    }

    public function parseLengthOrPercent(string $value): float|RelativeLength|PercentLength|CalcLength|null
    {
        $parsed = $this->parseLength($value);
        if ($parsed !== null) return $parsed;

        $trimmed = strtolower(trim($value));
        if (preg_match('/^(-?\d+(?:\.\d+)?)%$/', $trimmed, $m)) {
            return new PercentLength(((float) $m[1]) / 100);
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)(cqw|cqh|cqi|cqb|cqmin|cqmax)$/', $trimmed, $m)) {
            $ratio = ((float) $m[1]) / 100;
            $args = [$m[2] => $ratio];
            return new CalcLength(...$args);
        }
        return $this->parseCalc($trimmed);
    }

    public function parseLengthOrAuto(string $value): float|RelativeLength|PercentLength|CalcLength|string|null
    {
        return strtolower(trim($value)) === 'auto' ? 'auto' : $this->parseLengthOrPercent($value);
    }

    public function parseCalc(string $value): ?CalcLength
    {
        if (!str_starts_with($value, 'calc(') || !str_ends_with($value, ')')) return null;
        $inner = trim(substr($value, 5, -1));
        if ($inner === '') return null;
        preg_match_all('/([+-]?)\s*(\d+(?:\.\d+)?)\s*(px|pt|cm|mm|q|in|pc|%|em|rem|cqw|cqh|cqi|cqb|cqmin|cqmax)/i', $inner, $matches, PREG_SET_ORDER);
        if ($matches === []) return null;

        $acc = ['px'=>0.0,'percent'=>0.0,'em'=>0.0,'rem'=>0.0,'cqw'=>0.0,'cqh'=>0.0,'cqi'=>0.0,'cqb'=>0.0,'cqmin'=>0.0,'cqmax'=>0.0];
        foreach ($matches as $m) {
            $sign = $m[1] === '-' ? -1.0 : 1.0;
            $n = (float) $m[2] * $sign;
            $unit = strtolower($m[3]);
            if ($unit === '%') { $acc['percent'] += $n / 100; continue; }
            if (str_starts_with($unit, 'cq')) { $acc[$unit] += $n / 100; continue; }
            if ($unit === 'em' || $unit === 'rem') { $acc[$unit] += $n; continue; }
            $acc['px'] += match ($unit) {
                'px' => $n, 'pt' => Units::ptToPx($n), 'cm' => Units::cmToPx($n), 'mm' => Units::mmToPx($n),
                'q' => Units::qToPx($n), 'in' => Units::inToPx($n), 'pc' => Units::pcToPx($n), default => 0.0,
            };
        }
        return new CalcLength(...$acc);
    }
}
