<?php

declare(strict_types=1);

namespace Pagyra\Image;

final readonly class ObjectPosition
{
    public function __construct(
        public float $x = 0.5,
        public float $y = 0.5,
    ) {}
}
