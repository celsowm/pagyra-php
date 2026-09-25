<?php

declare(strict_types=1);

namespace Pagyra\Layout;

/**
 * Tracks the left/right cursors, shared starting Y and tallest bottom of a run of
 * consecutive `float: left|right` siblings within one containing block, so they can be
 * placed side by side at the same row instead of stacking vertically like normal-flow
 * children.
 */
final readonly class FloatRun
{
    public function __construct(
        public float $leftX,
        public float $rightX,
        public bool $active = false,
        public float $bottom = 0.0,
        public float $startY = 0.0,
        public ?float $leftBottom = null,
        public ?float $rightBottom = null,
    ) {
    }

    public function withLeft(float $newLeftX, float $childBottom, float $rowY): self
    {
        $startY = $this->active ? $this->startY : $rowY;
        $leftBottom = max($this->leftBottom ?? 0.0, $childBottom);
        $bottom = max($leftBottom, $this->rightBottom ?? 0.0);
        return new self($newLeftX, $this->rightX, true, $bottom, $startY, $leftBottom, $this->rightBottom);
    }

    public function withRight(float $newRightX, float $childBottom, float $rowY): self
    {
        $startY = $this->active ? $this->startY : $rowY;
        $rightBottom = max($this->rightBottom ?? 0.0, $childBottom);
        $bottom = max($this->leftBottom ?? 0.0, $rightBottom);
        return new self($this->leftX, $newRightX, true, $bottom, $startY, $this->leftBottom, $rightBottom);
    }

    public function reset(float $leftX, float $rightX): self
    {
        return new self($leftX, $rightX, false, 0.0, 0.0);
    }
}
