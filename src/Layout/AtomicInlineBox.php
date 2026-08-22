<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Style\ComputedStyle;
use Pagyra\Style\StyledNode;

final readonly class AtomicInlineBox implements \JsonSerializable
{
    public function __construct(
        public StyledNode $source,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ComputedStyle $style,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'node' => $this->source->node,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'style' => $this->style,
        ];
    }
}
