<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Geometry\Rect;
use Pagyra\Image\ImageMetadata;
use Pagyra\Layout\AtomicInlineBox;

final readonly class ImagePaintCommand implements \JsonSerializable
{
    public function __construct(
        public AtomicInlineBox $box,
        public int $pageIndex,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $bytes,
        public ImageMetadata $metadata,
        public string $source,
        public ?Rect $clipRect = null,
        public ?BorderRadius $clipRadius = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'image',
            'pageIndex' => $this->pageIndex,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'clipRect' => $this->clipRect,
            'clipRadius' => $this->clipRadius,
            'format' => $this->metadata->format,
            'intrinsicWidth' => $this->metadata->width,
            'intrinsicHeight' => $this->metadata->height,
            'source' => $this->source,
        ];
    }
}
