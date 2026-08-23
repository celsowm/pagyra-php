<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Layout\LayoutNode;

final readonly class StrokedBorderPaintCommand extends BorderPaintCommand
{
    /** @param list<float> $dashPattern */
    public function __construct(
        LayoutNode|AtomicInlineBox $node,
        int $pageIndex,
        string $side,
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
        public float $lineWidth,
        Rgba $color,
        public array $dashPattern,
        public float $dashPhase = 0.0,
        public string $lineCap = 'butt',
    ) {
        if (!in_array($lineCap, ['butt', 'round', 'square'], true)) {
            throw new \InvalidArgumentException('Unsupported line cap');
        }
        parent::__construct(
            node: $node,
            pageIndex: $pageIndex,
            side: $side,
            x: min($x1, $x2),
            y: min($y1, $y2),
            width: abs($x2 - $x1),
            height: abs($y2 - $y1),
            color: $color,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'stroked-border',
            'side' => $this->side,
            'pageIndex' => $this->pageIndex,
            'x1' => $this->x1,
            'y1' => $this->y1,
            'x2' => $this->x2,
            'y2' => $this->y2,
            'lineWidth' => $this->lineWidth,
            'color' => $this->color,
            'dashPattern' => $this->dashPattern,
            'dashPhase' => $this->dashPhase,
            'lineCap' => $this->lineCap,
            'node' => $this->node,
        ];
    }
}
