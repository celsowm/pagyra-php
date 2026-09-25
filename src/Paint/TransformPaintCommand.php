<?php

declare(strict_types=1);

namespace Pagyra\Paint;

/** Opens/closes a CSS transform graphics-state scope around subsequent paint commands. */
final readonly class TransformPaintCommand implements \JsonSerializable
{
    public function __construct(
        public int $pageIndex,
        public ?TransformMatrix $matrix = null,
        public float $originX = 0.0,
        public float $originY = 0.0,
    ) {
    }

    public function opens(): bool
    {
        return $this->matrix !== null;
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->opens() ? 'transform' : 'transform-end',
            'pageIndex' => $this->pageIndex,
            'matrix' => $this->matrix,
            'originX' => $this->originX,
            'originY' => $this->originY,
        ];
    }
}
