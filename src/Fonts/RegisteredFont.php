<?php

declare(strict_types=1);

namespace Pagyra\Fonts;

use Pagyra\Fonts\Ttf\TtfFontMetrics;

final readonly class RegisteredFont
{
    public function __construct(
        public string $family,
        public int $weight,
        public string $style,
        public TtfFontMetrics $metrics,
        public string $binary,
    ) {
    }
}
