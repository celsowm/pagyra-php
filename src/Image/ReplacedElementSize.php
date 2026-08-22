<?php

declare(strict_types=1);

namespace Pagyra\Image;

final readonly class ReplacedElementSize
{
    public function __construct(
        public float $width,
        public float $height,
    ) {
        if ($width < 0.0 || $height < 0.0) {
            throw new \InvalidArgumentException('Replaced element dimensions must be non-negative');
        }
    }
}
