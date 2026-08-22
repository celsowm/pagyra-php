<?php

declare(strict_types=1);

namespace Pagyra\Layout;

final readonly class LineBox implements \JsonSerializable
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $baseline,
        public string $text,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'baseline' => $this->baseline,
            'text' => $this->text,
        ];
    }
}
