<?php

declare(strict_types=1);

namespace Pagyra\Paint;

use Pagyra\Css\Color\Rgba;
use Pagyra\Layout\AtomicInlineBox;

/**
 * One flattened SVG shape expressed as absolute page-coordinate M/L/C/Z segments.
 *
 * @param list<array<string,float|string>> $segments
 */
final readonly class SvgPathPaintCommand implements \JsonSerializable
{
    public function __construct(
        public AtomicInlineBox $box,
        public int $pageIndex,
        public array $segments,
        public ?Rgba $fill = null,
        public ?Rgba $stroke = null,
        public float $strokeWidth = 1.0,
        public string $fillRule = 'nonzero',
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'svg-path',
            'pageIndex' => $this->pageIndex,
            'segments' => $this->segments,
            'fill' => $this->fill,
            'stroke' => $this->stroke,
            'strokeWidth' => $this->strokeWidth,
            'fillRule' => $this->fillRule,
        ];
    }
}
