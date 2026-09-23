<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;

/**
 * A linear or radial gradient filling the rectangle `x, y, width, height` (page px), as a PDF
 * axial or radial shading. For `linear`, `geometry` is `[x0, y0, x1, y1]`, the start and end of
 * the gradient line; for `radial`, `[cx, cy, rx, ry]`, the centre and the radii of the ending
 * shape. `stops` are `[offset 0..1, colour]`, in order.
 */
final readonly class GradientPaintCommand implements \JsonSerializable
{
    /**
     * @param 'linear'|'radial' $kind
     * @param list<float> $geometry
     * @param list<array{0:float,1:Rgba}> $stops
     */
    public function __construct(
        public mixed $node,
        public int $pageIndex,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $kind,
        public array $geometry,
        public array $stops,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'gradient',
            'kind' => $this->kind,
            'pageIndex' => $this->pageIndex,
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'geometry' => $this->geometry,
            'stops' => array_map(static fn(array $stop): array => ['offset' => $stop[0], 'color' => $stop[1]], $this->stops),
        ];
    }
}
