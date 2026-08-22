<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

use Pagyra\Style\ComputedStyle;

interface TextMetrics
{
    public function measure(string $text, ComputedStyle $style, float $fontSize): TextMeasurement;

    public function lineHeight(ComputedStyle $style, float $fontSize): float;
}
