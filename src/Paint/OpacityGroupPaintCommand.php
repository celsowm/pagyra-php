<?php

declare(strict_types=1);

namespace Pagyra\Paint;

/**
 * Opens/closes an isolated opacity group in the display list.
 *
 * The PDF serializer materializes an open/close pair as a Transparency Group Form XObject and
 * applies `opacity` once when drawing that form. Commands inside the group are normalized by
 * OpacityGroupNormalizer so ancestor opacity is not applied twice.
 */
final readonly class OpacityGroupPaintCommand implements \JsonSerializable
{
    public function __construct(
        public int $pageIndex,
        public ?float $opacity = null,
    ) {
    }

    public function opens(): bool
    {
        return $this->opacity !== null;
    }

    public function normalizedOpacity(): float
    {
        return max(0.0, min(1.0, $this->opacity ?? 1.0));
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => $this->opens() ? 'opacity-group' : 'opacity-group-end',
            'pageIndex' => $this->pageIndex,
            'opacity' => $this->opacity,
        ];
    }
}
