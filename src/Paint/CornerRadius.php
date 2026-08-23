<?php

declare(strict_types=1);

namespace Pagyra\Paint;

final readonly class CornerRadius implements \JsonSerializable
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}
