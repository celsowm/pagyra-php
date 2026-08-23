<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\LayoutNode;

final readonly class RoundedBorderPaintCommand implements \JsonSerializable
{
    public function __construct(
        public LayoutNode $node,
        public int $pageIndex,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $borderWidth,
        public Rgba $color,
        public BorderRadius $outerRadius,
        public BorderRadius $innerRadius,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'rounded-border',
            'pageIndex' => $this->pageIndex,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'borderWidth' => $this->borderWidth,
            'color' => $this->color,
            'outerRadius' => $this->outerRadius,
            'innerRadius' => $this->innerRadius,
            'node' => $this->node,
        ];
    }
}
