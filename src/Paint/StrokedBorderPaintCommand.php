<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\AtomicInlineBox;
use Pagyra\Layout\LayoutNode;

final readonly class StrokedBorderPaintCommand implements \JsonSerializable
{
    /** @param list<float> $dashPattern */
    public function __construct(
        public LayoutNode|AtomicInlineBox $node,
        public int $pageIndex,
        public string $side,
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
        public float $lineWidth,
        public Rgba $color,
        public array $dashPattern,
        public float $dashPhase = 0.0,
        public string $lineCap = 'butt',
    ) {
        if (!in_array($side, ['top', 'right', 'bottom', 'left'], true)) {
            throw new \InvalidArgumentException('Unsupported border side');
        }
        if (!in_array($lineCap, ['butt', 'round', 'square'], true)) {
            throw new \InvalidArgumentException('Unsupported line cap');
        }
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
