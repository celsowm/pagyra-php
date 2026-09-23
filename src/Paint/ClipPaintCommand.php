<?php

declare(strict_types=1);

namespace Pagyra\Paint;

/**
 * Opens (`$rect` set) or closes (`$rect` null) a clipping region around the painting of a box's
 * content, for `overflow: hidden|clip` (CSS Overflow 3 §3). The serializer turns the pair into
 * `q … re W n` … `Q`, so they must stay balanced and nested.
 */
final readonly class ClipPaintCommand implements \JsonSerializable
{
    public function __construct(
        public int $pageIndex,
        public ?float $x = null,
        public ?float $y = null,
        public ?float $width = null,
        public ?float $height = null,
    ) {
    }

    public function opens(): bool
    {
        return $this->x !== null;
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->opens() ? 'clip' : 'clip-end',
            'pageIndex' => $this->pageIndex,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
