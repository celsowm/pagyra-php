<?php

declare(strict_types=1);

namespace Pagyra\Layout;

use Pagyra\Style\ComputedStyle;
use Pagyra\Style\StyledNode;

final readonly class AtomicInlineBox implements \JsonSerializable
{
    /** @param array{top:float,right:float,bottom:float,left:float} $margin
     *  @param array{top:float,right:float,bottom:float,left:float} $padding
     *  @param array{top:float,right:float,bottom:float,left:float} $border
     */
    public function __construct(
        public StyledNode $source,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ComputedStyle $style,
        public float $contentWidth = 0.0,
        public float $contentHeight = 0.0,
        public array $margin = ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        public array $padding = ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
        public array $border = ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
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
            'contentWidth' => $this->contentWidth,
            'contentHeight' => $this->contentHeight,
            'margin' => $this->margin,
            'padding' => $this->padding,
            'border' => $this->border,
            'style' => $this->style,
        ];
    }
}
