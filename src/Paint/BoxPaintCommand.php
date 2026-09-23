<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Layout\LayoutNode;
use Pagyra\Layout\TextRun;

final readonly class BoxPaintCommand implements \JsonSerializable
{
    public function __construct(
        /** A TextRun is the background of an inline element behind that run's glyphs. */
        public LayoutNode|AtomicInlineBox|TextRun $node,
        public int $pageIndex,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?Rgba $backgroundColor = null,
        public BorderRadius $borderRadius = new BorderRadius(),
        /**
         * A box-shadow layer or an outline side: painted like a background, but not the node's
         * own background, so border handling keyed on the node must leave it alone.
         */
        public bool $decorative = false,
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
