<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Geometry\Rect;
use Pagyra\Image\ImageMetadata;
use Pagyra\Layout\AtomicInlineBox;

final readonly class ImagePaintCommand implements \JsonSerializable
{
    public AtomicInlineBox $box;
    public int $pageIndex;
    public float $x;
    public float $y;
    public float $width;
    public float $height;
    public string $bytes;
    public ImageMetadata $metadata;
    public string $source;
    public ?Rect $clipRect;
    public ?BorderRadius $clipRadius;

    public function __construct(
        AtomicInlineBox $box,
        int $pageIndex,
        float $x,
        float $y,
        float $width,
        float $height,
        string $bytes,
        ImageMetadata $metadata,
        string $source,
        ?Rect $clipRect = null,
        ?BorderRadius $clipRadius = null,
    ) {
        $this->box = $box;
        $this->pageIndex = $pageIndex;
        $this->x = $x;
        $this->y = $y;
        $this->width = $width;
        $this->height = $height;
        $this->bytes = $bytes;
        $this->metadata = $metadata;
        $this->source = $source;
        $this->clipRect = $clipRect;
        $this->clipRadius = $clipRadius ?? $this->deriveClipRadius($box, $clipRect);
    }

    private function deriveClipRadius(AtomicInlineBox $box, ?Rect $clipRect): ?BorderRadius
    {
        if ($clipRect === null) return null;

        $outerWidth = $box->contentWidth
            + $box->padding['left'] + $box->padding['right']
            + $box->border['left'] + $box->border['right'];
        $outerHeight = $box->contentHeight
            + $box->padding['top'] + $box->padding['bottom']
            + $box->border['top'] + $box->border['bottom'];
        $outer = BorderRadiusResolver::resolve($box->style, $outerWidth, $outerHeight);
        $paddingRadius = BorderRadiusResolver::shrink(
            $outer,
            $box->border['top'],
            $box->border['right'],
            $box->border['bottom'],
            $box->border['left'],
        );
        $contentRadius = BorderRadiusResolver::shrink(
            $paddingRadius,
            $box->padding['top'],
            $box->padding['right'],
            $box->padding['bottom'],
            $box->padding['left'],
        );
        return BorderRadiusResolver::normalize($contentRadius, $clipRect->width, $clipRect->height);
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
