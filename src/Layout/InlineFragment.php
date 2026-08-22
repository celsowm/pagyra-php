<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Style\ComputedStyle;

final readonly class InlineFragment
{
    public function __construct(
        public string $text,
        public ComputedStyle $style,
        public float $fontSize,
    ) {
    }
}
