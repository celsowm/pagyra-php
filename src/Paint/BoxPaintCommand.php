<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Layout\LayoutNode;

final readonly class BoxPaintCommand implements \JsonSerializable
{
    public function __construct(
        public LayoutNode|AtomicInlineBox $node,
        public int $pageIndex,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?Rgba $backgroundColor = null,
        public BorderRadius $borderRadius = new BorderRadius(),
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'box',
            'pageIndex' => $this->pageIndex,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'backgroundColor' => $this->backgroundColor,
            'borderRadius' => $this->borderRadius,
            'node' => $this->node,
        ];
    }
}
