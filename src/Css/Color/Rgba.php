<?php

declare(strict_types=1);

namespace Pagyra\Css\Color;

final readonly class Rgba implements \JsonSerializable
{
    public function __construct(
        public float $r,
        public float $g,
        public float $b,
        public float $a = 1.0,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['r' => $this->r, 'g' => $this->g, 'b' => $this->b, 'a' => $this->a];
    }

    public function toPdfRgb(): array
    {
        return [$this->r / 255, $this->g / 255, $this->b / 255];
    }
}
