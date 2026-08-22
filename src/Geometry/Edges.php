<?php

declare(strict_types=1);

namespace Pagyra\Geometry;

final readonly class Edges
{
    public function __construct(
        public float $top = 0.0,
        public float $right = 0.0,
        public float $bottom = 0.0,
        public float $left = 0.0,
    ) {
    }

    public function horizontal(): float
    {
        return $this->left + $this->right;
    }

    public function vertical(): float
    {
        return $this->top + $this->bottom;
    }
}
