<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\LayoutNode;

final readonly class BorderPaintCommand implements \JsonSerializable
{
    public function __construct(
        public LayoutNode $node,
        public int $pageIndex,
        public string $side,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public Rgba $color,
    ) {
        if (!in_array($side, ['top', 'right', 'bottom', 'left'], true)) {
            throw new \InvalidArgumentException('Unsupported border side');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'border',
            'side' => $this->side,
            'pageIndex' => $this->pageIndex,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'color' => $this->color,
            'node' => $this->node,
        ];
    }
}
