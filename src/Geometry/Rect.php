<?php

declare(strict_types=1);

namespace Pagyra\Geometry;

final readonly class Rect implements \JsonSerializable
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Rect width and height must be non-negative');
        }
    }

    public function right(): float
    {
        return $this->x + $this->width;
    }

    public function bottom(): float
    {
        return $this->y + $this->height;
    }

    public function jsonSerialize(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
